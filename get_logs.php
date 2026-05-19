<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
// التأكد من أن المستخدم مدير فقط
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'غير مصرح لك بالوصول!']);
    exit;
}

require 'db.php';

try {
    $stmt = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 200");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>