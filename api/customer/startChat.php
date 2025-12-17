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

if (!array_key_exists('customerId', $payload)) {
    $respond(400, [
        'success' => false,
        'message' => 'Missing field: customerId.'
    ]);
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

    $stmt = $db->prepare(
        'SELECT
            c.name,
            c.specific_address,
            c.location_description,
            a.city,
            a.sub_city
        FROM customer c
        LEFT JOIN address a ON a.id = c.address_id
        WHERE c.id = :customerId
        LIMIT 1'
    );
    $stmt->execute([':customerId' => $customerId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        $respond(404, [
            'success' => false,
            'message' => 'Customer not found.',
            'customerId' => $customerId
        ]);
    }

    $addressParts = [];
    if (!empty($customer['specific_address'])) {
        $addressParts[] = trim((string)$customer['specific_address']);
    }
    if (!empty($customer['location_description'])) {
        $addressParts[] = trim((string)$customer['location_description']);
    }

    $cityParts = array_filter([
        $customer['sub_city'] ?? null,
        $customer['city'] ?? null
    ], static fn($value) => $value !== null && $value !== '');

    if (!empty($cityParts)) {
        $addressParts[] = implode(', ', $cityParts);
    }

    $address = trim(implode(', ', $addressParts));

    $respond(200, [
        'success' => true,
        'message' => 'Chat context prepared.',
        'customerName' => (string)$customer['name'],
        'address' => $address,
        'deliverTime' => 'we deliver as soon as possible but at least we deliver in the same day you ordered',
        'model' => 'google/gemini-2.5-flash',
        'apiKey' => getenv('apiKey') ?: '',
        'document' => 'you are ai assistant you answer users/retailers question for our customer service merkatoPro.you main roll is to convince the user to user merkatoPro b2b market place. when they use merkato pro they can easly order from the comfort of their shop, they  compare supplier price, and get credits',
        'faq' => [
            'what I get if I use merkatoPro?',
            'how credit works in merkatoPro?'
        ]
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
