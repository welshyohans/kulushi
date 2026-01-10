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
include_once '../../model/SMS.php';

$db_connection = new Database();
$conn = $db_connection->connect();

// GET DATA FROM REQUEST
$data = json_decode(file_get_contents("php://input"));
if (!$data) {
    respond(400, 'error', 'Invalid JSON payload');
}

$requiredFields = ['customerId', 'cashAmount', 'creditAmount', 'totalAmount', 'items'];
foreach ($requiredFields as $field) {
    if (!property_exists($data, $field)) {
        respond(400, 'error', "Missing field: {$field}");
    }
}
if (!is_array($data->items) || empty($data->items)) {
    respond(400, 'error', 'items must be a non-empty array');
}

// MAIN RECIEVEING AND PROCESSING ORDER
// CHECK IF RECEIVED DATA IS NOT EMPTY
$nonEmptyFields = ['customerId', 'totalAmount'];
foreach ($nonEmptyFields as $field) {
    $value = $data->{$field} ?? null;
    if ($value === null || (is_string($value) && trim($value) === '')) {
        respond(400, 'error', "{$field} cannot be empty");
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

// Calculate profit from items (commission per goods)
$totalProfit = 0.0;
$pricingStmt = $conn->prepare(
    'SELECT sg.price AS supplier_price, g.commission AS goods_commission
     FROM supplier_goods sg
     INNER JOIN goods g ON g.id = sg.goods_id
     WHERE sg.id = :supplierGoodsId
     LIMIT 1'
);

try {
    $conn->beginTransaction();

    if (!$order->create()) {
        $conn->rollBack();
        respond(500, 'error', 'Error creating order');
    }

    $order_id = $conn->lastInsertId();

    // CREATE ORDERED LIST
    $ordered_list = new OrderedList($conn);
    foreach ($data->items as $item) {
        foreach (['supplierGoodsId', 'goodsId', 'quantity', 'unitPrice', 'subtotal', 'eligibleForCredit'] as $itemField) {
            if (!property_exists($item, $itemField)) {
                $conn->rollBack();
                respond(400, 'error', "Missing item field: {$itemField}");
            }
        }
        $supplierGoodsId = (int)$item->supplierGoodsId;
        $quantity = (int)$item->quantity;
        $pricingStmt->execute([':supplierGoodsId' => $supplierGoodsId]);
        $pricing = $pricingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $supplierPrice = isset($pricing['supplier_price']) ? (float)$pricing['supplier_price'] : 0.0;
        $commission = isset($pricing['goods_commission']) ? (float)$pricing['goods_commission'] : 0.0;
        $lineProfit = $commission * $quantity;

        $ordered_list->orders_id = $order_id;
        $ordered_list->supplier_goods_id = $supplierGoodsId;
        $ordered_list->goods_id = $item->goodsId;
        $ordered_list->quantity = $quantity;
        $ordered_list->each_price = $item->unitPrice;
        $ordered_list->supplier_price = $supplierPrice;
        $ordered_list->commission = $commission;
        $ordered_list->line_profit = $lineProfit;
        $ordered_list->eligible_for_credit = $item->eligibleForCredit ? 1 : 0;
        $ordered_list->status = 0; // Default status
        if (!$ordered_list->create()) {
            $conn->rollBack();
            respond(500, 'error', 'Error creating ordered list');
        }

        $totalProfit += $lineProfit;
    }

    $order->profit = $totalProfit;
    $profitStmt = $conn->prepare('UPDATE orders SET profit = :profit WHERE id = :orderId');
    $profitStmt->execute([
        ':profit' => number_format($totalProfit, 2, '.', ''),
        ':orderId' => $order_id
    ]);

    $conn->commit();

    try {
        sendOrderNotifications($conn, (int)$order_id, (int)$order->customer_id, (float)$order->total_price);
    } catch (Throwable $notificationError) {
        error_log('Order SMS notification failed for order ' . $order_id . ': ' . $notificationError->getMessage());
    }

    // Return status/message shape expected by OrderResponse
    respond(201, 'success', "Order created successfully (id: {$order_id})");
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Order insertion failed: ' . $e->getMessage());
    respond(500, 'error', 'Internal server error');
}

// Replace existing respond function to accept status string and message
function sendOrderNotifications($conn, int $orderId, int $customerId, float $totalAmount): void
{
    $customerStmt = $conn->prepare('SELECT name, phone FROM customer WHERE id = :customer_id LIMIT 1');
    $customerStmt->execute([':customer_id' => $customerId]);
    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    $customerName = $customer['name'] ?? '';
    $rawCustomerPhone = $customer['phone'] ?? null;
    $customerPhone = formatEthiopianPhone($rawCustomerPhone);

    $sms = new SMS();

    if ($customerPhone !== null) {
        $formattedAmount = number_format($totalAmount, 2);
        $message = "Your order has been received successfully. Telegram: Tiktok ";
        $sms->sendSms($customerPhone, $message);
    } else {
        error_log("Unable to send order confirmation SMS for order {$orderId}: customer phone missing or invalid.");
    }

    $adminPhone = formatEthiopianPhone('0943090921');
    if ($adminPhone !== null) {
        $adminMessage = "New order #{$orderId} placed amount {$formattedAmount} by customer ID {$customerId}" . ($customerName ? " ({$customerName})" : '') . ".";
        $sms->sendSms($adminPhone, $adminMessage);
    } else {
        error_log("Unable to send admin SMS for order {$orderId}: admin phone invalid.");
    }
}

function formatEthiopianPhone(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return null;
    }

    if (strpos($digits, '251') === 0) {
        $national = substr($digits, 3);
    } elseif ($digits[0] === '0') {
        $national = substr($digits, 1);
    } else {
        $national = $digits;
    }

    if (strlen($national) === 9) {
        return '+251' . $national;
    }

    return null;
}

function respond(int $statusCode, string $status, string $message): void {
    http_response_code($statusCode);
    echo json_encode([
        'status' => $status,
        'message' => $message
    ]);
    exit;
}
