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

$requiredFields = ['customerId', 'cashAmount', 'creditAmount', 'totalAmount', 'items'];
foreach ($requiredFields as $field) {
    if (!property_exists($data, $field)) {
        respond(400, ['success' => false, 'message' => "Missing field: {$field}"]);
    }
}
if (!is_array($data->items) || empty($data->items)) {
    respond(400, ['success' => false, 'message' => 'items must be a non-empty array']);
}

// MAIN RECIEVEING AND PROCESSING ORDER
// CHECK IF RECEIVED DATA IS NOT EMPTY
$nonEmptyFields = ['customerId', 'totalAmount'];
foreach ($nonEmptyFields as $field) {
    $value = $data->{$field} ?? null;
    if ($value === null || (is_string($value) && trim($value) === '')) {
        respond(400, ['success' => false, 'message' => "{$field} cannot be empty"]);
    }
}

// CREATE ORDER
$order = new Orders($conn);
$order->customer_id = $data->customerId;
$order->total_price = $data->totalAmount;
$order->cash_amount = $data->cashAmount;
$order->credit_amount = $data->creditAmount;
$order->deliver_status = property_exists($data, 'deliverStatus') ? $data->deliverStatus : 0;
$order->comment = property_exists($data, 'comment') ? (string) $data->comment : '';

// Calculate profit from items
$totalProfit = 0;
foreach ($data->items as $item) {
    // Profit calculation can be done here if needed
    // For now, we'll set it to 0 or calculate based on business logic
}
$order->profit = $totalProfit;

try {
    $conn->beginTransaction();

    if (!$order->create()) {
        $conn->rollBack();
        respond(500, ['success' => false, 'message' => 'Error creating order']);
    }

    $order_id = $conn->lastInsertId();

    // CREATE ORDERED LIST
    $ordered_list = new OrderedList($conn);
    foreach ($data->items as $item) {
        foreach (['supplierGoodsId', 'goodsId', 'quantity', 'unitPrice', 'subtotal', 'eligibleForCredit'] as $itemField) {
            if (!property_exists($item, $itemField)) {
                $conn->rollBack();
                respond(400, ['success' => false, 'message' => "Missing item field: {$itemField}"]);
            }
        }
        $ordered_list->orders_id = $order_id;
        $ordered_list->supplier_goods_id = $item->supplierGoodsId;
        $ordered_list->goods_id = $item->goodsId;
        $ordered_list->quantity = $item->quantity;
        $ordered_list->each_price = $item->unitPrice;
        $ordered_list->eligible_for_credit = $item->eligibleForCredit ? 1 : 0;
        $ordered_list->status = 0; // Default status
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
