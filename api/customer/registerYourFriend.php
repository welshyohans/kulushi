<?php
header('Content-Type: application/json');

include_once '../../config/Database.php';
include_once '../../model/SMS.php';

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

$required = ['name', 'shopName', 'phone', 'addressId', 'registerarCustomerId'];
foreach ($required as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, ['success' => false, 'message' => "Missing field: {$field}"]);
    }
}

$name = trim((string)$data['name']);
$shopName = trim((string)$data['shopName']);
$rawPhone = (string)$data['phone'];
$addressId = (int)$data['addressId'];
$registrarId = (int)$data['registerarCustomerId'];

if ($name === '') {
    $respond(422, ['success' => false, 'message' => 'name is required']);
}
if ($shopName === '') {
    $respond(422, ['success' => false, 'message' => 'shopName is required']);
}
if (trim($rawPhone) === '') {
    $respond(422, ['success' => false, 'message' => 'phone is required']);
}
if ($addressId <= 0) {
    $respond(422, ['success' => false, 'message' => 'addressId must be a positive integer']);
}
if ($registrarId <= 0) {
    $respond(422, ['success' => false, 'message' => 'registerarCustomerId must be a positive integer']);
}

$standardizePhone = function (string $input) use ($respond): string {
    $digits = preg_replace('/\D+/', '', $input);
    if ($digits === '') {
        $respond(422, ['success' => false, 'message' => 'phone is invalid']);
    }

    if (strlen($digits) === 9) {
        return '0' . $digits;
    }

    if (strlen($digits) === 10 && $digits[0] === '0') {
        return $digits;
    }

    if (strncmp($digits, '251', 3) === 0 && strlen($digits) >= 12) {
        return '0' . substr($digits, -9);
    }

    $respond(422, ['success' => false, 'message' => 'phone format is not supported']);
};

$phone = $standardizePhone($rawPhone);
$db = null;

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $registrarStatement = $db->prepare('SELECT id FROM customer WHERE id = :id LIMIT 1');
    $registrarStatement->execute([':id' => $registrarId]);
    if (!$registrarStatement->fetchColumn()) {
        $respond(404, ['success' => false, 'message' => 'Registrar customer not found', 'registerarCustomerId' => $registrarId]);
    }

    $phoneStatement = $db->prepare('SELECT id FROM customer WHERE phone = :phone LIMIT 1');
    $phoneStatement->execute([':phone' => $phone]);
    if ($phoneStatement->fetchColumn()) {
        $respond(409, ['success' => false, 'message' => 'Phone number already registered']);
    }

    $addressStatement = $db->prepare('SELECT sub_city FROM address WHERE id = :id LIMIT 1');
    $addressStatement->execute([':id' => $addressId]);
    $addressRow = $addressStatement->fetch();
    if (!$addressRow) {
        $respond(404, ['success' => false, 'message' => 'Address not found', 'addressId' => $addressId]);
    }

    $specificAddress = (string)$addressRow['sub_city'];
    $password = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

    $db->beginTransaction();

    $insert = $db->prepare(
        'INSERT INTO customer (name, phone, shop_name, password, registered_by, address_id, specific_address, location, location_description, user_type, firebase_code, fast_delivery_value, use_telegram, total_credit, total_unpaid, permitted_credit, delivery_time_info)
         VALUES (:name, :phone, :shop_name, :password, :registered_by, :address_id, :specific_address, :location, :location_description, :user_type, :firebase_code, :fast_delivery_value, :use_telegram, :total_credit, :total_unpaid, :permitted_credit, :delivery_time_info)'
    );

    $insert->execute([
        ':name' => $name,
        ':phone' => $phone,
        ':shop_name' => $shopName,
        ':password' => $password,
        ':registered_by' => $registrarId,
        ':address_id' => $addressId,
        ':specific_address' => $specificAddress,
        ':location' => '',
        ':location_description' => '',
        ':user_type' => '0',
        ':firebase_code' => '0',
        ':fast_delivery_value' => -1,
        ':use_telegram' => 0,
        ':total_credit' => 0,
        ':total_unpaid' => 0,
        ':permitted_credit' => 0,
        ':delivery_time_info' => ''
    ]);

    $newCustomerId = (int)$db->lastInsertId();

    $db->commit();

    $sms = new SMS();
    $nationalPhone = substr($phone, -9);
    $recipient = '+251' . $nationalPhone;
    $message = "Welcome to Merkato Pro. Your password is {$password}.";
    $sms->sendSms($recipient, $message);

    $respond(201, [
        'success' => true,
        'message' => 'Customer registered successfully',
        'customerId' => $newCustomerId
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