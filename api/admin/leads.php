<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

require_once __DIR__ . '/../../config/Database.php';

function ensureUnknownAddress(PDO $db): int
{
    $stmt = $db->prepare('SELECT id FROM address WHERE city = :city AND sub_city = :sub_city LIMIT 1');
    $stmt->execute([':city' => 'Unknown', ':sub_city' => 'Unknown']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['id'])) {
        return (int)$row['id'];
    }

    $insert = $db->prepare(
        'INSERT INTO address (city, sub_city, last_update_code, has_supplier)
         VALUES (:city, :sub_city, 0, 0)'
    );
    $insert->execute([':city' => 'Unknown', ':sub_city' => 'Unknown']);

    return (int)$db->lastInsertId();
}

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, ['success' => false, 'message' => 'Database connection failed.']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare(
            'SELECT id, name, phone, segment, lead_source, register_at
             FROM customer
             WHERE COALESCE(is_lead, 0) = 1
             ORDER BY register_at DESC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $respond(200, ['success' => true, 'count' => count($rows), 'items' => $rows]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '', true);
    if (!is_array($payload)) {
        $respond(400, ['success' => false, 'message' => 'Invalid JSON payload.']);
    }

    $name = isset($payload['name']) ? trim((string)$payload['name']) : '';
    $phone = isset($payload['phone']) ? trim((string)$payload['phone']) : '';
    $source = isset($payload['source']) ? trim((string)$payload['source']) : '';
    $registeredBy = isset($payload['registeredBy']) ? (int)$payload['registeredBy'] : 0;
    $addressId = isset($payload['addressId']) ? (int)$payload['addressId'] : 0;

    if ($name === '' || $phone === '') {
        $respond(422, ['success' => false, 'message' => 'name and phone are required.']);
    }

    if ($addressId <= 0) {
        $addressId = ensureUnknownAddress($db);
    }

    $stmt = $db->prepare(
        'INSERT INTO customer (
            name, phone, shop_name, password, registered_by, address_id, specific_address,
            location, location_description, user_type, firebase_code, fast_delivery_value,
            use_telegram, total_credit, total_unpaid, permitted_credit, latitude, longitude,
            delivery_time_info, segment, is_lead, lead_source
        ) VALUES (
            :name, :phone, :shop_name, :password, :registered_by, :address_id, :specific_address,
            :location, :location_description, :user_type, :firebase_code, :fast_delivery_value,
            :use_telegram, :total_credit, :total_unpaid, :permitted_credit, :latitude, :longitude,
            :delivery_time_info, :segment, :is_lead, :lead_source
        )'
    );

    $stmt->execute([
        ':name' => $name,
        ':phone' => $phone,
        ':shop_name' => $payload['shopName'] ?? 'Potential customer',
        ':password' => $payload['password'] ?? '',
        ':registered_by' => $registeredBy,
        ':address_id' => $addressId,
        ':specific_address' => $payload['specificAddress'] ?? '',
        ':location' => $payload['location'] ?? '',
        ':location_description' => $payload['locationDescription'] ?? '',
        ':user_type' => $payload['userType'] ?? '0',
        ':firebase_code' => $payload['firebaseCode'] ?? '0',
        ':fast_delivery_value' => $payload['fastDeliveryValue'] ?? -1,
        ':use_telegram' => $payload['useTelegram'] ?? 0,
        ':total_credit' => $payload['totalCredit'] ?? 0,
        ':total_unpaid' => $payload['totalUnpaid'] ?? 0,
        ':permitted_credit' => $payload['permittedCredit'] ?? 0,
        ':latitude' => $payload['latitude'] ?? 0,
        ':longitude' => $payload['longitude'] ?? 0,
        ':delivery_time_info' => $payload['deliveryTimeInfo'] ?? '',
        ':segment' => 'potential',
        ':is_lead' => 1,
        ':lead_source' => $source === '' ? null : $source
    ]);

    $respond(201, ['success' => true, 'message' => 'Lead added.']);
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
