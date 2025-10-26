<?php
// SET HEADER
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: access");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// INCLUDING DATABASE AND MAKING OBJECT
include_once '../../config/Database.php';
include_once '../../model/Orders.php';
include_once '../../model/OrderedList.php';

$db_connection = new Database();
$conn = $db_connection->connect();

// GET DATA FROM REQUEST
$data = json_decode(file_get_contents("php://input"));
if (!$data) {
    respond(400, ['success' => false, 'message' => 'Invalid JSON payload']);
}

$requiredFields = ['customer_id', 'total_price', 'profit', 'available_credit', 'deliver_status', 'ordered_list'];
foreach ($requiredFields as $field) {
    if (!property_exists($data, $field)) {
        respond(400, ['success' => false, 'message' => "Missing field: {$field}"]);
    }
}
if (!is_array($data->ordered_list) || empty($data->ordered_list)) {
    respond(400, ['success' => false, 'message' => 'ordered_list must be a non-empty array']);
}

// MAIN RECIEVEING AND PROCESSING ORDER
// CHECK IF RECEIVED DATA IS NOT EMPTY
$nonEmptyFields = ['customer_id', 'total_price', 'profit', 'available_credit', 'deliver_status'];
foreach ($nonEmptyFields as $field) {
    $value = $data->{$field} ?? null;
    if ($value === null || (is_string($value) && trim($value) === '')) {
        respond(400, ['success' => false, 'message' => "{$field} cannot be empty"]);
    }
}

// CREATE ORDER
$order = new Orders($conn);
$order->customer_id = $data->customer_id;
$order->total_price = $data->total_price;
$order->profit = $data->profit;
$order->available_credit = $data->available_credit;
$order->deliver_status = $data->deliver_status;
$order->comment = property_exists($data, 'comment') ? (string) $data->comment : '';

try {
    $conn->beginTransaction();

    if (!$order->create()) {
        $conn->rollBack();
        respond(500, ['success' => false, 'message' => 'Error creating order']);
    }

    $order_id = $conn->lastInsertId();

    // CREATE ORDERED LIST
    $ordered_list = new OrderedList($conn);
    foreach ($data->ordered_list as $item) {
        foreach (['supplier_goods_id', 'quantity', 'each_price', 'status'] as $itemField) {
            if (!property_exists($item, $itemField)) {
                $conn->rollBack();
                respond(400, ['success' => false, 'message' => "Missing ordered_list field: {$itemField}"]);
            }
        }
        $ordered_list->orders_id = $order_id;
        $ordered_list->supplier_goods_id = $item->supplier_goods_id;
        $ordered_list->quantity = $item->quantity;
        $ordered_list->each_price = $item->each_price;
        $ordered_list->status = $item->status;
        if (!$ordered_list->create()) {
            $conn->rollBack();
            respond(500, ['success' => false, 'message' => 'Error creating ordered list']);
        }
    }

    $conn->commit();
    respond(201, ['success' => true, 'message' => 'Order created successfully', 'order_id' => $order_id]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Order insertion failed: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Internal server error']);
}

function respond(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}
