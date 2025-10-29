<?php

header('Content-Type: application/json');

require_once '../../config/Database.php';
require_once '../../model/NotificationManagement.php';

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$requiredKeys = [
    'customerId',
    'fcmPayment',
    'fcmDelivering',
    'fcmOrdering',
    'fcmPriceChange',
    'fcmNewProduct',
    'smsPayment',
    'smsDelivering',
    'smsOrdering',
    'smsAds',
];

$missing = array_diff($requiredKeys, array_keys($payload));

if (!empty($missing)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields', 'fields' => array_values($missing)]);
    exit;
}

$database = new Database();
$conn = $database->connect();

$notificationManagement = new NotificationManagement($conn);

$normalized = [
    'customer_id' => (int)$payload['customerId'],
    'fcm_payment' => convertToTinyInt($payload['fcmPayment']),
    'fcm_delivering' => convertToTinyInt($payload['fcmDelivering']),
    'fcm_ordering' => convertToTinyInt($payload['fcmOrdering']),
    'fcm_price_change' => convertToTinyInt($payload['fcmPriceChange']),
    'fcm_new_product' => convertToTinyInt($payload['fcmNewProduct']),
    'sms_payment' => convertToTinyInt($payload['smsPayment']),
    'sms_delivering' => convertToTinyInt($payload['smsDelivering']),
    'sms_ordering' => convertToTinyInt($payload['smsOrdering']),
    'sms_ads' => convertToTinyInt($payload['smsAds']),
];

$record = $notificationManagement->upsert($normalized);

$response = [
    'id' => $record['id'] ?? null,
    'customerId' => $normalized['customer_id'],
    'fcmPayment' => (bool)$normalized['fcm_payment'],
    'fcmDelivering' => (bool)$normalized['fcm_delivering'],
    'fcmOrdering' => (bool)$normalized['fcm_ordering'],
    'fcmPriceChange' => (bool)$normalized['fcm_price_change'],
    'fcmNewProduct' => (bool)$normalized['fcm_new_product'],
    'smsPayment' => (bool)$normalized['sms_payment'],
    'smsDelivering' => (bool)$normalized['sms_delivering'],
    'smsOrdering' => (bool)$normalized['sms_ordering'],
    'smsAds' => (bool)$normalized['sms_ads'],
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);

function convertToTinyInt($value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_numeric($value)) {
        return ((int)$value) !== 0 ? 1 : 0;
    }

    $value = strtolower((string)$value);
    return in_array($value, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}