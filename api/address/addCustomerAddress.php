<?php
header('Content-Type: application/json');

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
$data = json_decode($raw, true);
if (!is_array($data)) {
    $respond(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

foreach (['customerId', 'addressId', 'addressName'] as $required) {
    if (!array_key_exists($required, $data)) {
        $respond(400, ['success' => false, 'message' => "Missing field: {$required}"]);
    }
}

$customerId = (int)$data['customerId'];
$addressId = (int)$data['addressId'];
$addressName = trim((string)$data['addressName']);
if ($customerId <= 0) {
    $respond(422, ['success' => false, 'message' => 'customerId must be a positive integer']);
}
if ($addressId <= 0) {
    $respond(422, ['success' => false, 'message' => 'addressId must be a positive integer']);
}
if ($addressName === '') {
    $respond(422, ['success' => false, 'message' => 'addressName is required']);
}

$isMainAddress = 0;
if (array_key_exists('isMainAddress', $data)) {
    $bool = filter_var($data['isMainAddress'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($bool === null) {
        $respond(422, ['success' => false, 'message' => 'isMainAddress must be boolean']);
    }
    $isMainAddress = $bool ? 1 : 0;
}
$priority = array_key_exists('priority', $data) ? (int)$data['priority'] : 0;
if ($priority < 0) {
    $respond(422, ['success' => false, 'message' => 'priority must be zero or positive']);
}

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $exists = $db->prepare('SELECT 1 FROM customer WHERE id = :id LIMIT 1');
    $exists->execute([':id' => $customerId]);
    if (!$exists->fetchColumn()) {
        $respond(404, ['success' => false, 'message' => 'Customer not found', 'customerId' => $customerId]);
    }

    $addressExists = $db->prepare('SELECT 1 FROM address WHERE id = :id LIMIT 1');
    $addressExists->execute([':id' => $addressId]);
    if (!$addressExists->fetchColumn()) {
        $respond(404, ['success' => false, 'message' => 'Address not found', 'addressId' => $addressId]);
    }

    $db->beginTransaction();
    if ($isMainAddress === 1) {
        $clear = $db->prepare('UPDATE customer_address SET is_main_address = 0 WHERE customer_id = :cid');
        $clear->execute([':cid' => $customerId]);
    }

    $insert = $db->prepare('INSERT INTO customer_address (customer_id, address_id, address_name, is_main_address, priority) VALUES (:customer_id, :address_id, :address_name, :is_main_address, :priority)');
    $insert->execute([
        ':customer_id' => $customerId,
        ':address_id' => $addressId,
        ':address_name' => $addressName,
        ':is_main_address' => $isMainAddress,
        ':priority' => $priority
    ]);
    $newId = (int)$db->lastInsertId();

    $fetch = $db->prepare('SELECT * FROM customer_address WHERE id = :id');
    $fetch->execute([':id' => $newId]);
    $row = $fetch->fetch();

    $db->commit();

    $respond(201, [
        'success' => true,
        'message' => 'Customer address added',
        'customerAddress' => $row
    ]);
} catch (PDOException $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}
