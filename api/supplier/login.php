<?php
header('Content-Type: application/json');

include_once '../../config/Database.php';

$response = function (int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
};


//check if any post request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response(405, ['success' => false, 'message' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $response(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

foreach (['phone', 'password'] as $field) {
    if (!array_key_exists($field, $data) || trim((string)$data[$field]) === '') {
        $response(400, ['success' => false, 'message' => "Missing or empty field: {$field}"]);
    }
}

$phone = trim((string)$data['phone']);
$password = trim((string)$data['password']);

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare('SELECT shop_id FROM supplier WHERE phone = :phone AND password = :password LIMIT 1');
    $stmt->execute([
        ':phone' => $phone,
        ':password' => $password
    ]);
    $supplierId = $stmt->fetchColumn();

    if ($supplierId === false) {
        $response(200, [
            'success' => false,
            'supplier_id' => 0,
            'message' => 'Invalid phone or password'
        ]);
    }

    $response(200, [
        'success' => true,
        'supplier_id' => (int)$supplierId
    ]);
} catch (PDOException $e) {
    $response(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    $response(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}
