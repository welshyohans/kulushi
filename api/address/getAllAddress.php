<?php
// CORS headers - allow cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include_once '../../config/Database.php';
include_once '../../model/Address.php';

$database = new Database();
$db = $database->connect();

$address = new Address($db);

echo json_encode($address->getAllAddress());


?>