<?php
// إعدادات الاتصال بقاعدة البيانات
$host = 'localhost';
$dbname = 'pddxdtmy_altabaay_delivery';
$username = 'pddxdtmy_obyprime';
$password = 'fghRTU65657&^&';

try {
    // إنشاء الاتصال بصيغة PDO الآمنة
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // تفعيل وضع التنبيهات للأخطاء
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage());
}
?>