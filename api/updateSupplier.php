<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, [
        'success' => false,
        'message' => 'Unable to read request body.'
    ]);
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    $respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.'
    ]);
}

foreach (['shopId', 'shopName', 'phone', 'password'] as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, [
            'success' => false,
            'message' => "Missing field: {$field}"
        ]);
    }
}

$shopId = filter_var($data['shopId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($shopId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'shopId must be a positive integer.'
    ]);
}

$shopName = trim((string)$data['shopName']);
$phone = trim((string)$data['phone']);
$password = (string)$data['password'];

if ($shopName === '') {
    $respond(422, [
        'success' => false,
        'message' => 'shopName cannot be empty.'
    ]);
}

if ($phone === '') {
    $respond(422, [
        'success' => false,
        'message' => 'phone cannot be empty.'
    ]);
}

if (strlen($password) < 4) {
    $respond(422, [
        'success' => false,
        'message' => 'password must be at least 4 characters long.'
    ]);
}

require_once __DIR__ . '/../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $supplierExistsStmt = $db->prepare('SELECT shop_id FROM supplier WHERE shop_id = :shopId LIMIT 1');
    $supplierExistsStmt->execute([':shopId' => $shopId]);

    if ($supplierExistsStmt->fetchColumn() === false) {
        $respond(404, [
            'success' => false,
            'message' => 'Supplier not found.',
            'shopId' => $shopId
        ]);
    }

    $updateStmt = $db->prepare(
        'UPDATE supplier
         SET shop_name = :shopName,
             phone = :phone,
             password = :password,
             last_update = NOW()
         WHERE shop_id = :shopId'
    );

    $updateStmt->bindValue(':shopName', $shopName);
    $updateStmt->bindValue(':phone', $phone);
    $updateStmt->bindValue(':password', $password);
    $updateStmt->bindValue(':shopId', $shopId, PDO::PARAM_INT);
    $updateStmt->execute();

    $rowsAffected = $updateStmt->rowCount();

    $respond(200, [
        'success' => true,
        'message' => $rowsAffected > 0
            ? 'Supplier updated successfully.'
            : 'No changes detected; supplier details remain the same.',
        'rowsAffected' => $rowsAffected
    ]);
} catch (PDOException $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Database error while updating supplier.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}