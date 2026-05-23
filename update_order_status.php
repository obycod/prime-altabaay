<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (isset($data['ids']) && is_array($data['ids']) && isset($data['status'])) {
    $ids = implode(',', array_map('intval', $data['ids']));
    $status = $data['status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE orders SET local_status = ? WHERE id IN ($ids)");
        $stmt->execute([$status]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>