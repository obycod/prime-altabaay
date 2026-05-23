<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (isset($data['ids']) && is_array($data['ids'])) {
    $ids = implode(',', array_map('intval', $data['ids']));

    // جلب أرقام التتبع (Prime Case IDs) من الطلبات المحددة
    $stmt = $pdo->query("SELECT tracking_no, carton_count FROM orders WHERE id IN ($ids) AND tracking_no IS NOT NULL AND tracking_no != ''");
    $prime_ids = [];
    while ($row = $stmt->fetch()) {
        $count = isset($row['carton_count']) ? (int)$row['carton_count'] : 1;
        if ($count < 1) $count = 1;
        for ($i = 0; $i < $count; $i++) {
            $prime_ids[] = (int)$row['tracking_no'];
        }
    }

    if(empty($prime_ids)){
        echo json_encode(['success' => false, 'error' => 'الطلبات المحددة لا تحتوي على أرقام تتبع من Prime. يجب إرسالها أولاً.']);
        exit;
    }

    // 1. إعدادات API شركة Prime الحقيقية
    $prime_token = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJJTlRFR1JBVEVEX1NZU1RFTV9DT0RFOkFMVEFCQkUiLCJpYXQiOjE3NzkyNjgwNTIsImV4cCI6MTc4MTg2MDA1Mn0.ZI8zgA1--K6nFzULnxCHxnm8m7zUPFEBUapa_Xaw_fU";
    $merchant_login_id = "AltabaayShop1"; // اليوزر الجديد الخاص بالطباعة
    $document_size = "A6"; 

    // توجيه الطلب للسيرفر الحقيقي (بدون كلمة devtest)
    $api_url = "https://prime-iq.com/myp/webapi/external/print-shipments/$merchant_login_id/$document_size";

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($prime_ids)); // إرسال مصفوفة الأرقام
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Accept: */*",
        "Authorization: Bearer " . $prime_token
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Prime ترجع رابط URL كـ String محاط بعلامات تنصيص
    if ($http_code == 200 && !empty($response)) {
        echo json_encode(['success' => true, 'pdf_url' => trim($response, '"')]);
    } else {
        echo json_encode(['success' => false, 'error' => 'فشل استخراج البوليصة من Prime.', 'details' => $response]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'بيانات مفقودة']);
}
?>