<?php
header('Content-Type: application/json; charset=utf-8');

session_start();
// التأكد من أن المستخدم مدير فقط
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'غير مصرح لك بالوصول!']);
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

require 'rate_limit.php';

require 'db.php';

try {
    $stmt = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 200");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>