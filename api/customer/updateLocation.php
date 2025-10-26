<?php
header('Content-Type: application/json');

require_once '../../config/Database.php';

$respond = function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, ['success' => false, 'message' => 'Unable to read request body']);
}

$body = json_decode($rawBody, true);
if (!is_array($body)) {
    $respond(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

$requiredFields = ['customer_id', 'phone', 'latitude', 'longitude'];
foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $body)) {
        $respond(400, ['success' => false, 'message' => "Missing field: {$field}"]);
    }
}

$customerId = (int)$body['customer_id'];
if ($customerId <= 0) {
    $respond(422, ['success' => false, 'message' => 'customer_id must be a positive integer']);
}

$phone = trim((string)$body['phone']);
if ($phone === '') {
    $respond(422, ['success' => false, 'message' => 'phone must be a non-empty string']);
}

if (!is_numeric($body['latitude']) || !is_numeric($body['longitude'])) {
    $respond(422, ['success' => false, 'message' => 'latitude and longitude must be numeric values']);
}

$latitude = (float)$body['latitude'];
$longitude = (float)$body['longitude'];

if ($latitude < -90 || $latitude > 90) {
    $respond(422, ['success' => false, 'message' => 'latitude must be between -90 and 90 degrees']);
}

if ($longitude < -180 || $longitude > 180) {
    $respond(422, ['success' => false, 'message' => 'longitude must be between -180 and 180 degrees']);
}

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, ['success' => false, 'message' => 'Unable to connect to database']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $customerRow = null;

    if ($customerId > 0) {
        $customerById = $db->prepare('SELECT id, phone FROM customer WHERE id = :id LIMIT 1');
        $customerById->execute([':id' => $customerId]);
        $customerRow = $customerById->fetch();
    }

    if (!$customerRow) {
        $customerByPhone = $db->prepare('SELECT id, phone FROM customer WHERE phone = :phone LIMIT 1');
        $customerByPhone->execute([':phone' => $phone]);
        $customerRow = $customerByPhone->fetch();
    }

    if (!$customerRow) {
        $respond(404, ['success' => false, 'message' => 'Customer not found']);
    }

    $persistedCustomerId = (int)$customerRow['id'];
    if ($customerId > 0 && $persistedCustomerId !== $customerId) {
        $respond(409, ['success' => false, 'message' => 'Provided customer details do not match existing records']);
    }

    $updateStatement = $db->prepare('UPDATE customer SET latitude = :latitude, longitude = :longitude WHERE id = :id');
    $updateStatement->execute([
        ':latitude' => $latitude,
        ':longitude' => $longitude,
        ':id' => $persistedCustomerId,
    ]);

    $respond(200, ['success' => true, 'message' => 'Location updated successfully']);
} catch (PDOException $e) {
    $respond(500, ['success' => false, 'message' => 'Database error']);
} catch (Throwable $t) {
    $respond(500, ['success' => false, 'message' => 'Server error']);
}