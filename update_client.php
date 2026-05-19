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

if ($data && isset($data['id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE clients SET client_type = ?, name = ?, phone = ?, phone2 = ?, province = ?, address = ? WHERE id = ?");
        $stmt->execute([
            htmlspecialchars(strip_tags($data['client_type']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['name']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['phone']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['phone2']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['province']), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(strip_tags($data['address']), ENT_QUOTES, 'UTF-8'),
            $data['id']
        ]);
        
        echo json_encode(['success' => true, 'message' => 'تم تحديث بيانات المكتب بنجاح']);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'بيانات الاتصال مفقودة أو غير مكتملة']);
}
?>