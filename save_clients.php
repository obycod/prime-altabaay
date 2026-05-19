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

if ($data && is_array($data)) {
    try {
        // تفريغ الجدول القديم
        $pdo->query("TRUNCATE TABLE clients");
        
        // تفعيل الـ Transaction لتسريع الحفظ المليوني ومنع ضياع البيانات
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO clients (client_type, name, phone, phone2, province, address) VALUES (?, ?, ?, ?, ?, ?)");
        
        foreach ($data as $client) {
            $stmt->execute([
                $client['client_type'],
                $client['name'], 
                $client['phone'], 
                $client['phone2'], 
                $client['province'],
                $client['address']
            ]);
        }
        
        // تنفيذ الحفظ دفعة واحدة
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'تم تحديث قاعدة بيانات المكاتب بنجاح']);
    } catch(PDOException $e) {
        $pdo->rollBack(); // التراجع في حال حدوث خطأ
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'لا توجد بيانات صالحة']);
}
?>