<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'غير مصرح لك بالوصول! يرجى تسجيل الدخول.']);
    exit;
}

// تسجيل الخروج التلقائي (Session Timeout)
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
    session_unset();
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'انتهت الجلسة بسبب الخمول. يرجى تسجيل الدخول مجدداً.']);
    exit;
}
$_SESSION['last_activity'] = time();

require 'rate_limit.php';

require 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

if ($data) {
    try {
        // إضافة حقل tracking_no في جدول الطلبات أوتوماتيكياً لو لم يكن موجوداً
        try {
            $pdo->query("ALTER TABLE orders ADD COLUMN tracking_no VARCHAR(100) NULL AFTER receipt_no");
        } catch (PDOException $e) {
            // الحقل موجود مسبقاً، لا تفعل شيئاً
        }

        // حفظ الطلب فقط في أرشيف الطلبات دون المساس بجدول العملاء
        $stmt = $pdo->prepare("INSERT INTO orders (client_name, phone, province, address, carton_count, amount, receipt_no) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            htmlspecialchars(strip_tags($data['clientName']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['phoneNumber']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['provinceName']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['address']), ENT_QUOTES, 'UTF-8'),
            $data['cartonCount'], 
            $data['amount'], 
            htmlspecialchars(strip_tags($data['receiptNo']), ENT_QUOTES, 'UTF-8')
        ]);
        
        $local_order_id = $pdo->lastInsertId();

        // تسجيل الحركة
        $action_details = "قام بإصدار طلب توصيل جديد لمكتب: " . strip_tags($data['clientName']);
        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (username, action_details) VALUES (?, ?)");
        $log_stmt->execute([$_SESSION['username'], $action_details]);
        
        // ==========================================
        // بداية الربط مع API شركة Prime
        // ==========================================
        $clientName = htmlspecialchars(strip_tags($data['clientName']), ENT_QUOTES, 'UTF-8');
        $phoneNumber = htmlspecialchars(strip_tags($data['phoneNumber']), ENT_QUOTES, 'UTF-8');
        $provinceName = htmlspecialchars(strip_tags($data['provinceName']), ENT_QUOTES, 'UTF-8');
        $address = htmlspecialchars(strip_tags($data['address']), ENT_QUOTES, 'UTF-8');
        $cartonCount = $data['cartonCount'];
        $amount = $data['amount'];
        $receiptNo = htmlspecialchars(strip_tags($data['receiptNo']), ENT_QUOTES, 'UTF-8');
        $notes = "عدد الكراتين الكلي للمكتبة ( $cartonCount )";
        $order_id = $local_order_id;

        // 1. إعدادات حساب برايم (يجب تعبئتها لاحقاً)
        $prime_token = "ضع_التوكن_هنا"; // Bearer Token
        $merchantLoginId = "ضع_يوزر_التاجر_هنا"; 
        $senderId = 12345; // رقم المتجر الخاص بك (Integer)
        
        // 2. مصفوفة تحويل أسماء المحافظات إلى شفرات برايم (يمكنك تعديلها لاحقاً)
        $state_codes = [
            "بغداد" => "BGD",
            "البصرة" => "BSR",
            "اربيل" => "EBL"
            // ... سيتم إضافة الباقي لاحقاً
        ];
        $prime_state = isset($state_codes[$provinceName]) ? $state_codes[$provinceName] : "BGD";

        // 3. تجهيز بيانات الطلب حسب صيغة برايم الدقيقة
        $prime_payload = [
            [
                "custReceiptNoOri" => $receiptNo ?: $order_id, // رقم الوصل
                "district" => "0", 
                "haveReturnItems" => "Y",
                "locationDetails" => $address, // العنوان
                "merchantLoginId" => $merchantLoginId,
                "productInfo" => "ملازم الطابعي - " . $notes, // نوع البضاعة
                "qty" => (int)$cartonCount, // عدد الكراتين
                "receiptAmtIqd" => (int)$amount, // المبلغ
                "receiverHp1" => $phoneNumber, // الهاتف
                "receiverName" => $clientName, // اسم المستلم
                "senderId" => $senderId,
                "senderSystemCaseIdWithCharacters" => "TBY-" . $order_id, // رقم الطلب في نظامنا
                "state" => $prime_state // شفرة المحافظة
            ]
        ];
        
        // 4. إرسال الطلب عبر cURL
        $ch = curl_init('https://devtest.prime-iq.com/myp/webapi/external/create-shipments');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($prime_payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Accept: application/json",
            "Authorization: Bearer " . $prime_token
        ]);
        
        $prime_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // 5. معالجة الرد وتحديث رقم التتبع في قاعدة البيانات
        $prime_data = json_decode($prime_response, true);
        $tracking_no = null;
        
        // ملاحظة: برايم ترجع ID الشحنة، سنقوم بتحديث الطلب بناءً عليه
        if ($http_code == 200 && !empty($prime_data)) {
            // استخراج رقم الشحنة الأول من المصفوفة الراجعة (قد يختلف الهيكل قليلاً حسب الرد الفعلي)
            $tracking_no = is_array($prime_data) ? json_encode($prime_data) : $prime_response; // مؤقتاً لحفظ الرد كاملاً والتأكد منه
            
            // تحديث حقل tracking_no في الطلب
            $stmt_update = $pdo->prepare("UPDATE orders SET tracking_no = ? WHERE id = ?");
            $stmt_update->execute([$tracking_no, $order_id]);
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'تم حفظ الطلب وإرساله لشركة برايم بنجاح!', 
            'tracking_no' => $tracking_no,
            'order_id' => $order_id
        ]);
        // =================================================================
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>