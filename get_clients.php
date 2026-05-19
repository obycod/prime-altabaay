<?php
header('Content-Type: application/json; charset=utf-8');
require 'db.php'; // استدعاء ملف الاتصال

try {
    // جلب كل المكاتب من قاعدة البيانات
    $stmt = $pdo->query("SELECT * FROM clients ORDER BY name ASC");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($clients);
} catch(PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>