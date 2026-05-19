<?php
header('Content-Type: application/json; charset=utf-8');
require 'db.php';

try {
    $stmt = $pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT 500");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($orders);
} catch(PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>