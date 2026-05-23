<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'غير مصرح']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (isset($data['ids']) && is_array($data['ids']) && !empty($data['ids']) && isset($data['status'])) {
    $status = $data['status'];
    
    $allowed_statuses = ['pending_print', 'ready_for_pickup', 'shipped'];
    if(in_array($status, $allowed_statuses)){
        $ids = array_map('intval', $data['ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $stmt = $pdo->prepare("UPDATE orders SET local_status = ? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$status], $ids));
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'حالة غير صالحة']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'بيانات مفقودة']);
}
?>