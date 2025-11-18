<?php
header('Content-Type: application/json; charset=utf-8');

include_once '../../model/SMS.php';
include_once '../../config/Database.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function normalisePhoneNumber($rawPhone)
{
    if (!is_string($rawPhone)) {
        return null;
    }

    $sanitised = preg_replace('/[^\d+]/', '', trim($rawPhone));
    if ($sanitised === null || $sanitised === '') {
        return null;
    }

    if (strpos($sanitised, '+') === 0) {
        return $sanitised;
    }

    if (strpos($sanitised, '0') === 0) {
        return '+251' . substr($sanitised, 1);
    }

    if (strpos($sanitised, '251') === 0) {
        return '+' . $sanitised;
    }

    if (preg_match('/^\d{9,12}$/', $sanitised)) {
        return '+251' . $sanitised;
    }

    return null;
}

function collectPhones(PDO $db, string $whereClause = '', array $params = []): array
{
    $query = 'SELECT phone FROM customer WHERE phone IS NOT NULL AND phone <> \'\'';
    if ($whereClause !== '') {
        $query .= ' AND ' . $whereClause;
    }

    $stmt = $db->prepare($query);
    $stmt->execute($params);

    $phones = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $normalised = normalisePhoneNumber($row['phone'] ?? '');
        if ($normalised !== null) {
            $phones[$normalised] = true;
        }
    }

    return array_keys($phones);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$rawInput = file_get_contents('php://input');
$data = [];

if ($rawInput !== false && trim($rawInput) !== '') {
    $decoded = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $data = $decoded;
    }
}

if (empty($data) && !empty($_POST)) {
    $data = $_POST;
}

$messageRaw = $data['message'] ?? $data['body'] ?? $data['text'] ?? null;
$senderRaw = $data['sender'] ?? $data['senderName'] ?? null;
$modeRaw = $data['mode'] ?? $data['audience'] ?? 'single';
$mode = strtolower(is_string($modeRaw) ? trim($modeRaw) : 'single');

$body = is_string($messageRaw) ? trim($messageRaw) : '';
if ($body === '') {
    respond(400, [
        'success' => false,
        'message' => 'Message body must not be empty.'
    ]);
}

if (!in_array($mode, ['single', 'address', 'all'], true)) {
    respond(422, [
        'success' => false,
        'message' => 'Invalid audience mode supplied.'
    ]);
}

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        respond(500, [
            'success' => false,
            'message' => 'Database connection could not be established.'
        ]);
    }

    $sms = new SMS();

    if ($mode === 'single') {
        $phoneRaw = $data['phone'] ?? $data['recipientPhone'] ?? null;
        $phone = normalisePhoneNumber($phoneRaw);

        if ($phone === null) {
            respond(400, [
                'success' => false,
                'message' => 'A valid recipient phone number is required for single sends.'
            ]);
        }

        $sms->sendSms($phone, $body);
        respond(200, [
            'success' => true,
            'message' => 'SMS request submitted successfully.',
            'payload' => [
                'mode' => 'single',
                'phone' => $phone,
                'messageLength' => strlen($body),
                'sender' => $senderRaw ?: null
            ]
        ]);
    }

    if ($mode === 'address') {
        $addressId = (int)($data['addressId'] ?? $data['address_id'] ?? 0);
        if ($addressId <= 0) {
            respond(422, [
                'success' => false,
                'message' => 'addressId must be provided for address-based messages.'
            ]);
        }

        $phones = collectPhones($db, 'address_id = :address_id', [':address_id' => $addressId]);
        if (empty($phones)) {
            respond(404, [
                'success' => false,
                'message' => 'No customers found for the selected address.',
                'payload' => [
                    'addressId' => $addressId
                ]
            ]);
        }

        $sms->addressBasedSms($phones, $body);
        respond(200, [
            'success' => true,
            'message' => 'Address-based SMS submitted successfully.',
            'payload' => [
                'mode' => 'address',
                'addressId' => $addressId,
                'targeted' => count($phones),
                'sender' => $senderRaw ?: null
            ]
        ]);
    }

    $phones = collectPhones($db);
    if (empty($phones)) {
        respond(404, [
            'success' => false,
            'message' => 'No customers with phone numbers are available for broadcast.'
        ]);
    }

    $sms->addressBasedSms($phones, $body);
    respond(200, [
        'success' => true,
        'message' => 'Broadcast SMS submitted successfully.',
        'payload' => [
            'mode' => 'all',
            'targeted' => count($phones),
            'sender' => $senderRaw ?: null
        ]
    ]);
} catch (PDOException $exception) {
    respond(500, [
        'success' => false,
        'message' => 'Failed to process SMS request.',
        'error' => $exception->getMessage()
    ]);
}
