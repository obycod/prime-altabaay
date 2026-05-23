<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized Access.");
}

// Check if IDs are passed via GET parameter
if (isset($_GET['ids']) && !empty($_GET['ids'])) {
    $ids_array = explode(',', $_GET['ids']);
    $ids = implode(',', array_map('intval', $ids_array));

    // Fetch tracking numbers based on order carton counts
    $stmt = $pdo->query("SELECT tracking_no, carton_count FROM orders WHERE id IN ($ids) AND tracking_no IS NOT NULL AND tracking_no != ''");
    $prime_ids = [];
    while ($row = $stmt->fetch()) {
        $count = isset($row['carton_count']) ? (int)$row['carton_count'] : 1;
        if ($count < 1) $count = 1;
        // Duplicate the tracking number to print a label for each carton
        for ($i = 0; $i < $count; $i++) {
            $prime_ids[] = (int)$row['tracking_no'];
        }
    }

    if (empty($prime_ids)) {
        die("Error: No Prime tracking numbers found for the selected orders. Please ensure orders are successfully submitted first.");
    }

    $prime_token = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJJTlRFR1JBVEVEX1NZU1RFTV9DT0RFOkFMVEFCQkUiLCJpYXQiOjE3NzkyNjgwNTIsImV4cCI6MTc4MTg2MDA1Mn0.ZI8zgA1--K6nFzULnxCHxnm8m7zUPFEBUapa_Xaw_fU";
    $merchant_login_id = "AltabaayShop1"; 
    $document_size = "A6"; 

    $api_url = "https://prime-iq.com/myp/webapi/external/print-shipments/$merchant_login_id/$document_size";

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($prime_ids)); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Accept: */*",
        "Authorization: Bearer " . $prime_token
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200 && !empty($response)) {
        $pdf_url = trim($response, '"');
        // Redirect the browser directly to the Prime PDF
        header("Location: " . $pdf_url);
        exit;
    } else {
        die("Error generating Waybill from Prime API. Details: " . htmlspecialchars($response));
    }
} else {
    die("Missing order IDs.");
}
?>