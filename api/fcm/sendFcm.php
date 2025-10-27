<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Access-Control-Allow-Headers,Content-Type,Access-Control-Allow-Methods, Authorization, X-Requested-With');

include_once '../../config/Database.php';
include_once '../../model/FCM.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST method is accepted.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"));

if (
    empty($data->fcmCode) ||
    empty($data->title) ||
    empty($data->body) ||
    !isset($data->dataPayload) ||
    (!is_array($data->dataPayload) && !is_object($data->dataPayload))
) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid parameters. Required: fcmCode, title, body, dataPayload (as array or object).']);
    exit;
}

$fcmCode = $data->fcmCode;
$title = $data->title;
$body = $data->body;
$dataPayload = (array) $data->dataPayload;

try {
    $fcm = new FCMService('from-merkato', '../../model/mp.json');
    // Assuming sendFCM can take a data payload as the fourth argument.
    $result = $fcm->sendFCM($fcmCode, $title, $body, $dataPayload);
    echo $result; // Assuming sendFCM returns a JSON string.
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'FCM notification failed: ' . $e->getMessage()]);
}
?>