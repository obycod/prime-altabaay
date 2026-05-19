<?php
$secret_key = 'YOUR_STRONG_KEY'; // استبدل هذه الكلمة برمز سري صعب ومعقد!

if (!isset($_GET['secret']) || $_GET['secret'] !== $secret_key) {
    http_response_code(403);
    die('Access Denied: Invalid Secret Key.');
}

require 'db.php';

$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

$date = date('Y-m-d_H-i-s');
$file_name = "backup_{$date}.sql";
$file_path = $backup_dir . '/' . $file_name;

try {
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $sql_dump = "-- Prime & Altabaay Database Backup\n";
    $sql_dump .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $createTable = $stmt->fetch(PDO::FETCH_NUM);
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql_dump .= $createTable[1] . ";\n\n";

        $stmt = $pdo->query("SELECT * FROM `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 0) {
            $sql_dump .= "INSERT INTO `$table` VALUES \n";
            $values = [];
            foreach ($rows as $row) {
                $rowValues = array_map(function($val) use ($pdo) { return is_null($val) ? "NULL" : $pdo->quote($val); }, $row);
                $values[] = "(" . implode(", ", $rowValues) . ")";
            }
            $sql_dump .= implode(",\n", $values) . ";\n\n";
        }
    }

    file_put_contents($file_path, $sql_dump);
    echo "✅ Backup successfully created: " . $file_name;
} catch (Exception $e) {
    http_response_code(500);
    echo "❌ Backup failed: " . $e->getMessage();
}
?>