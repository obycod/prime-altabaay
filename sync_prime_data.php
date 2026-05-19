<?php
session_start();
require 'db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'غير مصرح لك بالوصول!']);
    exit;
}

$prime_token = "YOUR_TOKEN_HERE"; // ضع التوكن الخاص بك هنا

function fetchPrimeData($url, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Authorization: Bearer " . $token
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

try {
    // 1. جلب ومزامنة المحافظات
    $states_data = fetchPrimeData('https://devtest.prime-iq.com/myp/webapi/general/states', $prime_token);
    $states_inserted = 0;
    
    if (!empty($states_data) && is_array($states_data)) {
        $data_array = isset($states_data['data']) ? $states_data['data'] : $states_data;
        $stmt_state = $pdo->prepare("INSERT INTO prime_states (state_code, state_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE state_name = VALUES(state_name)");
        foreach ($data_array as $key => $val) {
            $code = is_array($val) && isset($val['code']) ? $val['code'] : (is_array($val) && isset($val['id']) ? $val['id'] : $key);
            $name = is_array($val) && isset($val['name']) ? $val['name'] : (is_string($val) ? $val : json_encode($val));
            $stmt_state->execute([$code, $name]);
            $states_inserted++;
        }
    }

    // 2. جلب ومزامنة الحالات
    $statuses_data = fetchPrimeData('https://devtest.prime-iq.com/myp/webapi/external/list-of-system-steps', $prime_token);
    $statuses_inserted = 0;

    if (!empty($statuses_data) && is_array($statuses_data)) {
        $data_array = isset($statuses_data['data']) ? $statuses_data['data'] : $statuses_data;
        $stmt_status = $pdo->prepare("INSERT INTO prime_statuses (status_code, status_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE status_name = VALUES(status_name)");
        foreach ($data_array as $key => $val) {
            $code = is_array($val) && isset($val['code']) ? $val['code'] : (is_array($val) && isset($val['id']) ? $val['id'] : $key);
            $name = is_array($val) && isset($val['name']) ? $val['name'] : (is_string($val) ? $val : json_encode($val));
            $stmt_status->execute([$code, $name]);
            $statuses_inserted++;
        }
    }

    echo json_encode(['success' => true, 'message' => "تمت المزامنة بنجاح! تم تحديث $states_inserted محافظة و $statuses_inserted حالة."]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()]);
}
?>