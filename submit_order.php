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
            htmlspecialchars(strip_tags($data['clientName']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['phoneNumber']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['provinceName']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['address']), ENT_QUOTES, 'UTF-8'),
            $data['cartonCount'], 
            $data['amount'], 
            htmlspecialchars(strip_tags($data['receiptNo']), ENT_QUOTES, 'UTF-8')
        ]);
        
        echo json_encode(['success' => true, 'order_id' => $pdo->lastInsertId()]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>