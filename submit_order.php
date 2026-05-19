<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'غير مصرح لك بالوصول! يرجى تسجيل الدخول.']);
    exit;
}

require 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

if ($data) {
    try {
        // حفظ الطلب فقط في أرشيف الطلبات دون المساس بجدول العملاء
        $stmt = $pdo->prepare("INSERT INTO orders (client_name, phone, province, address, carton_count, amount, receipt_no) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['clientName'], 
            $data['phoneNumber'], 
            $data['provinceName'], 
            $data['address'],
            $data['cartonCount'], 
            $data['amount'], 
            $data['receiptNo']
        ]);
        
        echo json_encode(['success' => true, 'order_id' => $pdo->lastInsertId()]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>