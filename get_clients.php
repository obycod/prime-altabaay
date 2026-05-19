<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'غير مصرح لك بالوصول! يرجى تسجيل الدخول.']);
    exit;
}

// تسجيل الخروج التلقائي (Session Timeout)
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
    session_unset();
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'انتهت الجلسة بسبب الخمول. يرجى تسجيل الدخول مجدداً.']);
    exit;
}
$_SESSION['last_activity'] = time();

require 'rate_limit.php'; // حماية من الضغط وسحب البيانات

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