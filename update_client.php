<?php
header('Content-Type: application/json; charset=utf-8');
require 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

if ($data && isset($data['id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE clients SET client_type = ?, name = ?, phone = ?, phone2 = ?, province = ?, address = ? WHERE id = ?");
        $stmt->execute([
            $data['client_type'],
            $data['name'], 
            $data['phone'], 
            $data['phone2'], 
            $data['province'],
            $data['address'],
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