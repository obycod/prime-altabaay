<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rate_limit = 60; // أقصى عدد من الطلبات
$time_frame = 60; // خلال 60 ثانية

if (!isset($_SESSION['rate_limit_count'])) {
    $_SESSION['rate_limit_count'] = 1;
    $_SESSION['rate_limit_start_time'] = time();
} else {
    $time_elapsed = time() - $_SESSION['rate_limit_start_time'];
    if ($time_elapsed < $time_frame) {
        $_SESSION['rate_limit_count']++;
        if ($_SESSION['rate_limit_count'] > $rate_limit) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too Many Requests - لقد تجاوزت الحد المسموح من الطلبات. يرجى الانتظار قليلاً.']);
            exit;
        }
    } else {
        $_SESSION['rate_limit_count'] = 1;
        $_SESSION['rate_limit_start_time'] = time();
    }
}
?>