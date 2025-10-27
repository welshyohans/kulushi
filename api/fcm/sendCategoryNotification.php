<?php
/**
 * Endpoint to send category-specific notifications via FCM.
 *
 * Expected JSON payload (application/json):
 * {
 *   "token": "<FCM_TOKEN>",
 *   "category_id": "42",
 *   "title": "New arrivals",
 *   "body": "Tap to view category 42",
 *   "destination": "category",        // optional, defaults to "category"
 *   "is_notifable": 0,                // optional, 1 to include notification block
 *   "data": {                         // optional, merged into data payload
 *     "extra_key": "extra_value"
 *   },
 *   "project_id": "from-merkato",     // optional, overrides default project
 *   "service_account_path": "../../model/mp.json" // optional path override
 * }
 */
header('Content-Type: application/json');

require_once '../../model/FCM.php';

$rawInput = file_get_contents('php://input');
$input = [];

if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $input = $decoded;
    }
}

if (empty($input)) {
    $input = $_POST;
}

$token = $input['token'] ?? $input['fcm_token'] ?? null;
$categoryId = $input['category_id'] ?? null;
$title = $input['title'] ?? null;
$body = $input['body'] ?? null;
$destination = $input['destination'] ?? 'category';
$isNotifable = isset($input['is_notifable']) ? (int) $input['is_notifable'] : 0;

if (!$token || !$categoryId || !$title || !$body) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Required fields: token, category_id, title, body.',
    ]);
    exit;
}

$dataPayload = [
    'destination' => (string) $destination,
    'category_id' => (string) $categoryId,
    'title' => $title,
    'body' => $body,
];

if (isset($input['data']) && is_array($input['data'])) {
    $dataPayload = array_merge($dataPayload, $input['data']);
}

$projectId = $input['project_id'] ?? 'from-merkato';
$serviceAccountPath = $input['service_account_path'] ?? '../../model/mp.json';

if (!is_string($serviceAccountPath) || !file_exists($serviceAccountPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Service account file not found.',
    ]);
    exit;
}

try {
    $fcm = new FCMService($projectId, $serviceAccountPath);
    $result = $fcm->sendFCM($token, $title, $body, $isNotifable, null, $dataPayload);
} catch (Exception $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to initialize FCM service.',
        'error' => $exception->getMessage(),
    ]);
    exit;
}

$decodedResult = json_decode($result, true);

if (json_last_error() === JSON_ERROR_NONE && isset($decodedResult['name'])) {
    echo json_encode([
        'success' => true,
        'message' => 'Notification sent successfully.',
        'message_name' => $decodedResult['name'],
        'data_sent' => $dataPayload,
    ]);
    exit;
}

http_response_code(502);
echo json_encode([
    'success' => false,
    'message' => 'FCM did not acknowledge the notification.',
    'response' => $result,
]);

?>