<?php
// CORS headers - allow cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include_once '../../config/Database.php';

$respond = function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    $respond(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

foreach (['phone', 'password'] as $field) {
    if (!array_key_exists($field, $body) || trim((string)$body[$field]) === '') {
        $respond(400, ['success' => false, 'message' => "Missing or empty field: {$field}"]);
    }
}

$phone = trim((string)$body['phone']);
$password = trim((string)$body['password']);

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare('SELECT id FROM customer WHERE phone = :phone AND password = :password LIMIT 1');
    $stmt->execute([
        ':phone' => $phone,
        ':password' => $password
    ]);

    $customerId = $stmt->fetchColumn();

    if ($customerId === false) {
        $respond(200, [
            'success' => false,
            'customer_id' => 0,
            'message' => 'Invalid phone or password'
        ]);
    }

    $respond(200, [
        'success' => true,
        'customer_id' => (int)$customerId
    ]);
} catch (PDOException $e) {
    $respond(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    $respond(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}
