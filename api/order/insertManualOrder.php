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
    $respond(405, ['success' => false, 'message' => 'Method not allowed. Use POST.']);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, ['success' => false, 'message' => 'Unable to read request body.']);
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    $respond(400, ['success' => false, 'message' => 'Invalid JSON payload.']);
}

$requiredFields = ['customerId', 'date'];
foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, ['success' => false, 'message' => "Missing field: {$field}"]);
    }
}

$customerId = filter_var($data['customerId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($customerId === false) {
    $respond(422, ['success' => false, 'message' => 'customerId must be a positive integer.']);
}

$dateRaw = trim((string)$data['date']);
if ($dateRaw === '') {
    $respond(422, ['success' => false, 'message' => 'date must be a non-empty string representing a datetime.']);
}

try {
    $orderDate = new DateTime($dateRaw);
} catch (Throwable $dateError) {
    $respond(422, ['success' => false, 'message' => 'Invalid date format.', 'error' => $dateError->getMessage()]);
}

$orderTime = $orderDate->format('Y-m-d H:i:s');

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, ['success' => false, 'message' => 'Unable to establish database connection.']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $db->beginTransaction();

    $customerCheck = $db->prepare('SELECT 1 FROM customer WHERE id = :customerId LIMIT 1');
    $customerCheck->execute([':customerId' => $customerId]);
    if (!$customerCheck->fetchColumn()) {
        $db->rollBack();
        $respond(404, ['success' => false, 'message' => 'Customer not found.']);
    }

    $insertOrderStmt = $db->prepare(
        'INSERT INTO orders (customer_id, order_time) VALUES (:customerId, :orderTime)'
    );
    $insertOrderStmt->execute([
        ':customerId' => $customerId,
        ':orderTime' => $orderTime
    ]);

    $db->commit();

    $respond(201, ['success' => true, 'message' => 'Manual order created.']);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Database error while creating manual order.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected error while creating manual order.',
        'error' => $throwable->getMessage()
    ]);
}
