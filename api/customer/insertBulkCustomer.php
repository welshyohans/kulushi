<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/Database.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
}

// Attempt to locate the JSON payload from a multipart upload, POST field, or raw body.
$jsonPayload = null;
if (
    !empty($_FILES['customers_file']) &&
    $_FILES['customers_file']['error'] === UPLOAD_ERR_OK &&
    is_readable($_FILES['customers_file']['tmp_name'])
) {
    $jsonPayload = file_get_contents($_FILES['customers_file']['tmp_name']);
} elseif (isset($_POST['customers'])) {
    $jsonPayload = is_array($_POST['customers']) ? json_encode($_POST['customers']) : $_POST['customers'];
} else {
    $rawBody = trim(file_get_contents('php://input'));
    if ($rawBody !== '') {
        $jsonPayload = $rawBody;
    }
}

if ($jsonPayload === null || trim($jsonPayload) === '') {
    respond(400, [
        'success' => false,
        'message' => 'No JSON payload found. Upload a file named customers_file or send JSON in the request body.'
    ]);
}

$decoded = json_decode($jsonPayload, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.',
        'error' => json_last_error_msg()
    ]);
}

if (isset($decoded['customers']) && is_array($decoded['customers'])) {
    $customers = $decoded['customers'];
} elseif (is_array($decoded)) {
    // Distinguish between a list of customers and a single object.
    $isAssociative = count(array_filter(array_keys($decoded), 'is_string')) > 0;
    $customers = $isAssociative ? [$decoded] : $decoded;
} else {
    respond(400, [
        'success' => false,
        'message' => 'JSON payload must be an array of customers or contain a customers array.'
    ]);
}

if (empty($customers)) {
    respond(400, [
        'success' => false,
        'message' => 'Customer list is empty.'
    ]);
}

try {
    $database = new Database();
    $db = $database->connect();

    $checkStmt = $db->prepare('SELECT id FROM customer WHERE phone = :phone LIMIT 1');
    $insertStmt = $db->prepare(
        'INSERT INTO customer (
            name,
            phone,
            shop_name,
            password,
            registered_by,
            address_id,
            specific_address,
            location,
            location_description,
            register_at,
            user_type,
            firebase_code,
            fast_delivery_value,
            use_telegram,
            total_credit,
            total_unpaid,
            latitude,
            longitude,
            permitted_credit,
            delivery_time_info
        ) VALUES (
            :name,
            :phone,
            :shop_name,
            :password,
            :registered_by,
            :address_id,
            :specific_address,
            :location,
            :location_description,
            :register_at,
            :user_type,
            :firebase_code,
            :fast_delivery_value,
            :use_telegram,
            :total_credit,
            :total_unpaid,
            :latitude,
            :longitude,
            :permitted_credit,
            :delivery_time_info
        )'
    );

    $summary = [
        'success' => true,
        'attempted' => count($customers),
        'inserted' => 0,
        'skipped_existing' => 0,
        'failed' => 0,
        'details' => []
    ];

    foreach ($customers as $index => $customer) {
        if (!is_array($customer)) {
            $summary['failed']++;
            $summary['details'][] = [
                'index' => $index,
                'status' => 'error',
                'message' => 'Customer entry must be an object.'
            ];
            continue;
        }

        $name = trim($customer['name'] ?? '');
        $phone = trim($customer['phone'] ?? '');
        $shopName = trim($customer['shop_name'] ?? '');
        $password = (string)($customer['password'] ?? '');
        $addressId = isset($customer['address_id']) ? (int)$customer['address_id'] : 0;
        $locationDescription = trim($customer['location_description'] ?? '');
        $specificAddress = trim($customer['specific_address'] ?? '');

        if ($specificAddress === '') {
            $specificAddress = 'N/A';
        }

        if (
            $name === '' ||
            $phone === '' ||
            $shopName === '' ||
            $password === '' ||
            $addressId <= 0 ||
            $locationDescription === ''
        ) {
            $summary['failed']++;
            $summary['details'][] = [
                'index' => $index,
                'phone' => $phone,
                'status' => 'error',
                'message' => 'Missing one of the required fields: name, phone, shop_name, password, address_id, location_description.'
            ];
            continue;
        }

        $checkStmt->execute([':phone' => $phone]);
        if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
            $summary['skipped_existing']++;
            $summary['details'][] = [
                'phone' => $phone,
                'status' => 'exists',
                'message' => 'Customer already registered.'
            ];
            continue;
        }

        $latitude = null;
        $longitude = null;
        if (!empty($customer['location']) && is_string($customer['location'])) {
            $parts = array_map('trim', explode(',', $customer['location']));
            if (count($parts) >= 2) {
                $latValue = filter_var($parts[0], FILTER_VALIDATE_FLOAT);
                $longValue = filter_var($parts[1], FILTER_VALIDATE_FLOAT);
                $latitude = $latValue !== false ? $latValue : null;
                $longitude = $longValue !== false ? $longValue : null;
            }
        }

        try {
            $insertStmt->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':shop_name' => $shopName,
                ':password' => $password,
                ':registered_by' => 5,
                ':address_id' => $addressId,
                ':specific_address' => $specificAddress,
                ':location' => '',
                ':location_description' => $locationDescription,
                ':register_at' => date('Y-m-d H:i:s'),
                ':user_type' => '0',
                ':firebase_code' => '',
                ':fast_delivery_value' => 0,
                ':use_telegram' => 0,
                ':total_credit' => 0,
                ':total_unpaid' => 0,
                ':latitude' => $latitude,
                ':longitude' => $longitude,
                ':permitted_credit' => 0,
                ':delivery_time_info' => ''
            ]);

            $summary['inserted']++;
            $summary['details'][] = [
                'phone' => $phone,
                'status' => 'inserted',
                'customer_id' => $db->lastInsertId()
            ];
        } catch (PDOException $insertException) {
            $summary['failed']++;
            $summary['details'][] = [
                'phone' => $phone,
                'status' => 'error',
                'message' => $insertException->getMessage()
            ];
        }
    }

    respond(200, $summary);
} catch (PDOException $exception) {
    respond(500, [
        'success' => false,
        'message' => 'Failed to insert customers.',
        'error' => $exception->getMessage()
    ]);
}
