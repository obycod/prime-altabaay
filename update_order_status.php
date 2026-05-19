<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (isset($data['ids']) && is_array($data['ids']) && isset($data['status'])) {
    $ids = implode(',', array_map('intval', $data['ids']));
    $status = $data['status'];
    
    $allowed_statuses = ['pending_print', 'ready_for_pickup', 'shipped'];
    if(in_array($status, $allowed_statuses)){
        $pdo->query("UPDATE orders SET local_status = '$status' WHERE id IN ($ids)");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'حالة غير صالحة']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'بيانات مفقودة']);
}
?>