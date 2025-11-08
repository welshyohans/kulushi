<?php
header('Content-Type: application/json; charset=utf-8');

include_once '../../model/SMS.php';
include_once '../../config/Database.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
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

$phoneRaw = $data['phone'] ?? $data['recipientPhone'] ?? null;
$messageRaw = $data['message'] ?? $data['body'] ?? $data['text'] ?? null;
$senderRaw = $data['sender'] ?? $data['senderName'] ?? null;

$phone = normalisePhoneNumber($phoneRaw);
$body = is_string($messageRaw) ? trim($messageRaw) : '';

if ($phone === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'A valid recipient phone number is required.'
    ]);
    exit;
}

if ($body === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Message body must not be empty.'
    ]);
    exit;
}

$database = new Database();
$db = $database->connect();

$sms = new SMS();
$sms->sendSms($phone, $body);

echo json_encode([
    'success' => true,
    'message' => 'SMS request submitted successfully.',
    'payload' => [
        'phone' => $phone,
        'messageLength' => strlen($body),
        'sender' => $senderRaw ?: null
    ]
]);