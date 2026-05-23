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

        $clientName = htmlspecialchars(strip_tags($data['clientName']), ENT_QUOTES, 'UTF-8');
        $phoneNumber = htmlspecialchars(strip_tags($data['phoneNumber']), ENT_QUOTES, 'UTF-8');
        $provinceName = htmlspecialchars(strip_tags($data['provinceName']), ENT_QUOTES, 'UTF-8');
        $address = htmlspecialchars(strip_tags($data['address']), ENT_QUOTES, 'UTF-8');
        $cartonCount = (isset($data['cartonCount']) && $data['cartonCount'] !== '') ? (int)$data['cartonCount'] : 1;
        $bookletCount = (isset($data['bookletCount']) && $data['bookletCount'] !== '') ? (int)$data['bookletCount'] : 0;
        $amount = $data['amount'];
        $receiptNo = htmlspecialchars(strip_tags($data['receiptNo']), ENT_QUOTES, 'UTF-8');
        
        // STRICT FORMATTING REQUIRED BY USER
        $notes = "عدد الكراتين الكلي للمكتبة ( " . $cartonCount . " ) وعدد الملازم ( " . $bookletCount . " )";

        $stmt = $pdo->prepare("INSERT INTO orders (client_name, phone, province, address, carton_count, amount, receipt_no) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$clientName, $phoneNumber, $provinceName, $address, $cartonCount, $amount, $receiptNo]);
        
        $local_order_id = $pdo->lastInsertId();
        $order_id = $local_order_id;

        // تسجيل الحركة
        $action_details = "قام بإصدار طلب توصيل جديد لمكتب: " . strip_tags($data['clientName']);
        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (username, action_details) VALUES (?, ?)");
        $log_stmt->execute([$_SESSION['username'], $action_details]);
        
        // ==========================================
        // بداية الربط مع API شركة Prime
        // ==========================================

        // 1. إعدادات حساب برايم الحقيقية (الـ Live)
        $prime_token = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJJTlRFR1JBVEVEX1NZU1RFTV9DT0RFOkFMVEFCQkUiLCJpYXQiOjE3NzkyNjgwNTIsImV4cCI6MTc4MTg2MDA1Mn0.ZI8zgA1--K6nFzULnxCHxnm8m7zUPFEBUapa_Xaw_fU";
        $merchantLoginId = "AltabaayShop1"; // اليوزر الخاص بالتاجر الذي انشأناه
        $senderId = 134673; // رقم المتجر الذي تولد لدينا
        
        // 2. مصفوفة تحويل أسماء المحافظات إلى شفرات برايم (يمكنك تعديلها لاحقاً)
        $state_codes = [
            "بغداد" => "BGD",
            "ذي قار" => "NAS", "الناصرية" => "NAS",
            "ديالى" => "DYL",
            "واسط" => "KOT", "الكوت" => "KOT",
            "كربلاء" => "KRB",
            "دهوك" => "DOH",
            "بابل" => "BBL", "الحلة" => "BBL",
            "النجف" => "NJF",
            "البصرة" => "BAS",
            "اربيل" => "ARB",
            "كركوك" => "KRK",
            "السليمانية" => "SMH",
            "صلاح الدين" => "SAH",
            "الانبار" => "ANB",
            "المثنى" => "SAM", "السماوة" => "SAM",
            "نينوى" => "MOS", "الموصل" => "MOS", "موصل" => "MOS",
            "الديوانية" => "DWN", "القادسية" => "DWN",
            "ميسان" => "AMA", "العمارة" => "AMA"
        ];
        
        // كود الترجمة (إذا المحافظة غير موجودة، يعتبرها بغداد كافتراضي لتجنب توقف النظام)
        $prime_state = isset($state_codes[$provinceName]) ? $state_codes[$provinceName] : "BGD";

        // 3. تجهيز بيانات الطلب حسب صيغة برايم الدقيقة
        $prime_payload = [
            [
                "custReceiptNoOri" => $receiptNo ?: $order_id, // رقم الوصل
                "district" => "0", 
                "haveReturnItems" => "N",
                "locationDetails" => $address, // العنوان
                "merchantLoginId" => $merchantLoginId,
                "productInfo" => $notes,
                "qty" => 1, // MUST BE STRICTLY 1 to avoid Internal Server Error
                "receiptAmtIqd" => (int)$amount, // المبلغ
                "receiverHp1" => $phoneNumber, // الهاتف
                "receiverName" => $clientName, // اسم المستلم
                "senderId" => $senderId,
                "senderSystemCaseIdWithCharacters" => "TBY-" . $order_id, // رقم الطلب في نظامنا
                "state" => $prime_state // شفرة المحافظة
            ]
        ];
        
        // 4. إرسال الطلب عبر cURL للسيرفر الحقيقي
        $ch = curl_init('https://prime-iq.com/myp/webapi/external/create-shipments');
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
        
        // 5. استخراج رقم الشحنة (Shipment ID) بطريقة ذكية
        $tracking_no = null;
        
        if ($http_code == 200 && !empty($prime_response)) {
            $prime_data = json_decode($prime_response, true);
            
            if (is_array($prime_data)) {
                // إذا كان الرد مصفوفة، استخرج أول قيمة
                $tracking_no = reset($prime_data); 
            } else {
                // إذا كان الرد رقم مباشر أو نص، استخدمه
                $tracking_no = trim($prime_response, '"'); 
            }
            
            // تحديث حقل tracking_no في الطلب
            if ($tracking_no) {
                $stmt_update = $pdo->prepare("UPDATE orders SET tracking_no = ? WHERE id = ?");
                $stmt_update->execute([$tracking_no, $order_id]);
            }
        }
        
        // التحقق النهائي وإرسال الرد للواجهة
        if ($tracking_no) {
            echo json_encode([
                'success' => true, 
                'message' => 'تم حفظ الطلب وإرساله لشركة برايم بنجاح!', 
                'tracking_no' => $tracking_no,
                'order_id' => $order_id
            ]);
        } else {
            // إذا فشل الإرسال إلى برايم، أخبر المستخدم مع إظهار الرد الفعلي للسيرفر لتسهيل اكتشاف الخطأ
            echo json_encode([
                'success' => false, 
                'error' => 'تم الحفظ محلياً فقط. فشل الإرسال إلى برايم. رد السيرفر: ' . $prime_response
            ]);
            // ملاحظة: مسحنا الطلب المحلي إذا فشل الإرسال لتجنب تكرار الطلبات الخاطئة
            $pdo->query("DELETE FROM orders WHERE id = $order_id");
        }
        // =================================================================
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>