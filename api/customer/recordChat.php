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

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.'
    ]);
}

foreach (['customerId', 'userMessage', 'assistantMessage'] as $requiredField) {
    if (!array_key_exists($requiredField, $payload)) {
        $respond(400, [
            'success' => false,
            'message' => sprintf('Missing field: %s.', $requiredField)
        ]);
    }
}

$customerId = filter_var($payload['customerId'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);
if ($customerId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'customerId must be a positive integer.'
    ]);
}

$userMessage = is_string($payload['userMessage']) ? trim($payload['userMessage']) : '';
if ($userMessage === '') {
    $respond(422, [
        'success' => false,
        'message' => 'userMessage must be a non-empty string.'
    ]);
}

$assistantMessage = is_string($payload['assistantMessage']) ? trim($payload['assistantMessage']) : '';
if ($assistantMessage === '') {
    $respond(422, [
        'success' => false,
        'message' => 'assistantMessage must be a non-empty string.'
    ]);
}

$model = null;
if (array_key_exists('model', $payload)) {
    if (!is_string($payload['model'])) {
        $respond(422, [
            'success' => false,
            'message' => 'model must be a string when provided.'
        ]);
    }
    $model = trim($payload['model']) !== '' ? trim($payload['model']) : null;
}

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, [
            'success' => false,
            'message' => 'Database connection failed.'
        ]);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $customerStmt = $db->prepare('SELECT 1 FROM customer WHERE id = :customerId LIMIT 1');
    $customerStmt->execute([':customerId' => $customerId]);
    if ($customerStmt->fetchColumn() === false) {
        $respond(404, [
            'success' => false,
            'message' => 'Customer not found.',
            'customerId' => $customerId
        ]);
    }

    $insertStmt = $db->prepare(
        'INSERT INTO customer_chat_records (customer_id, user_message, assistant_message, model)
         VALUES (:customer_id, :user_message, :assistant_message, :model)'
    );

    $insertStmt->execute([
        ':customer_id' => $customerId,
        ':user_message' => $userMessage,
        ':assistant_message' => $assistantMessage,
        ':model' => $model
    ]);

    $respond(200, [
        'success' => true,
        'message' => 'Chat record saved.'
    ]);
} catch (PDOException $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Database error.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}
