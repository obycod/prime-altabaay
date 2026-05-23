<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'غير مصرح لك بإجراء هذه العملية.']);
    exit;
}

$prime_token = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJJTlRFR1JBVEVEX1NZU1RFTV9DT0RFOkFMVEFCQkUiLCJpYXQiOjE3NzkyNjgwNTIsImV4cCI6MTc4MTg2MDA1Mn0.ZI8zgA1--K6nFzULnxCHxnm8m7zUPFEBUapa_Xaw_fU";
$api_url = "https://prime-iq.com/myp/webapi/general/states";

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPGET, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Accept: application/json",
    "Authorization: Bearer " . $prime_token
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code == 200 && !empty($response)) {
    $states = json_decode($response, true);
    if(is_array($states) && count($states) > 0) {
        // تفريغ الجدول القديم لإعادة التعبئة
        $pdo->query("TRUNCATE TABLE prime_states");
        
        $stmt = $pdo->prepare("INSERT INTO prime_states (state_code, state_name) VALUES (?, ?)");
        $count = 0;
        foreach($states as $state) {
            if(isset($state['key']) && isset($state['val'])) {
                $stmt->execute([$state['key'], $state['val']]);
                $count++;
            }
        }
        echo json_encode(['success' => true, 'message' => "تمت مزامنة $count محافظة بنجاح."]);
    } else {
        echo json_encode(['success' => false, 'error' => 'البيانات الراجعة فارغة أو غير صحيحة.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'فشل الاتصال بسيرفر Prime.', 'details' => $response]);
}
?>