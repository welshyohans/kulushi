<?php

header('Content-Type: application/json');

require_once '../../config/Database.php';
require_once '../../model/NotificationManagement.php';

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!is_array($payload) || !array_key_exists('customerId', $payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'customerId is required']);
    exit;
}

$customerId = (int)$payload['customerId'];

$database = new Database();
$conn = $database->connect();

$notificationManagement = new NotificationManagement($conn);
$record = $notificationManagement->getByCustomerId($customerId);

$response = [
    'id' => null,
    'customerId' => $customerId,
    'fcmPayment' => true,
    'fcmDelivering' => true,
    'fcmOrdering' => true,
    'fcmPriceChange' => true,
    'fcmNewProduct' => true,
    'smsPayment' => true,
    'smsDelivering' => true,
    'smsOrdering' => true,
    'smsAds' => true,
];

if ($record !== null) {
    $response['id'] = (int)$record['id'];
    $response['customerId'] = (int)$record['customer_id'];
    $response['fcmPayment'] = (bool)intval($record['fcm_payment']);
    $response['fcmDelivering'] = (bool)intval($record['fcm_delivering']);
    $response['fcmOrdering'] = (bool)intval($record['fcm_ordering']);
    $response['fcmPriceChange'] = (bool)intval($record['fcm_price_change']);
    $response['fcmNewProduct'] = (bool)intval($record['fcm_new_product']);
    $response['smsPayment'] = (bool)intval($record['sms_payment']);
    $response['smsDelivering'] = (bool)intval($record['sms_delivering']);
    $response['smsOrdering'] = (bool)intval($record['sms_ordering']);
    $response['smsAds'] = (bool)intval($record['sms_ads']);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);