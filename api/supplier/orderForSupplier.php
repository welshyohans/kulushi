<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

require_once '../../config/Database.php';

$respond = function (int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['status' => 'error', 'message' => 'Method not allowed']);
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $respond(400, ['status' => 'error', 'message' => 'Invalid JSON payload']);
}

$requiredFields = ['customerId', 'totalPrice', 'orderTime', 'supplierOrderLists'];
foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, ['status' => 'error', 'message' => "Missing field: {$field}"]);
    }
}

$customerId = filter_var($data['customerId'], FILTER_VALIDATE_INT);
if ($customerId === false || $customerId <= 0) {
    $respond(400, ['status' => 'error', 'message' => 'Invalid customerId']);
}

$totalPrice = filter_var($data['totalPrice'], FILTER_VALIDATE_FLOAT);
if ($totalPrice === false || $totalPrice < 0) {
    $respond(400, ['status' => 'error', 'message' => 'Invalid totalPrice']);
}

$orderTimeInput = (string)$data['orderTime'];
try {
    $orderTime = new DateTime($orderTimeInput);
    $orderTimeFormatted = $orderTime->format('Y-m-d H:i:s');
} catch (Exception $exception) {
    $respond(400, ['status' => 'error', 'message' => 'Invalid orderTime format']);
}

$comment = array_key_exists('comment', $data) ? trim((string)$data['comment']) : null;
if ($comment === '') {
    $comment = null;
}

if (!is_array($data['supplierOrderLists']) || empty($data['supplierOrderLists'])) {
    $respond(400, ['status' => 'error', 'message' => 'supplierOrderLists must be a non-empty array']);
}

$items = [];
foreach ($data['supplierOrderLists'] as $index => $item) {
    if (!is_array($item)) {
        $respond(400, ['status' => 'error', 'message' => "Invalid item payload at index {$index}"]);
    }

    foreach (['orderId', 'goodsId', 'quantity', 'price'] as $itemField) {
        if (!array_key_exists($itemField, $item)) {
            $respond(400, ['status' => 'error', 'message' => "Missing field {$itemField} in supplierOrderLists[{$index}]"]);
        }
    }

    $orderId = filter_var($item['orderId'], FILTER_VALIDATE_INT);
    if ($orderId === false || $orderId <= 0) {
        $respond(400, ['status' => 'error', 'message' => "Invalid orderId in supplierOrderLists[{$index}]"]);
    }

    $goodsId = filter_var($item['goodsId'], FILTER_VALIDATE_INT);
    if ($goodsId === false || $goodsId <= 0) {
        $respond(400, ['status' => 'error', 'message' => "Invalid goodsId in supplierOrderLists[{$index}]"]);
    }

    $quantity = filter_var($item['quantity'], FILTER_VALIDATE_INT);
    if ($quantity === false || $quantity <= 0) {
        $respond(400, ['status' => 'error', 'message' => "Invalid quantity in supplierOrderLists[{$index}]"]);
    }

    $price = filter_var($item['price'], FILTER_VALIDATE_FLOAT);
    if ($price === false || $price < 0) {
        $respond(400, ['status' => 'error', 'message' => "Invalid price in supplierOrderLists[{$index}]"]);
    }

    $items[] = [
        'order_id' => $orderId,
        'goods_id' => $goodsId,
        'quantity' => $quantity,
        'price' => $price,
    ];
}

$db = null;

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $db->beginTransaction();

    $insertOrder = $db->prepare(
        'INSERT INTO supplier_order (customer_id, total_price, order_time, comment)
         VALUES (:customer_id, :total_price, :order_time, :comment)'
    );

    $insertOrder->execute([
        ':customer_id' => $customerId,
        ':total_price' => $totalPrice,
        ':order_time' => $orderTimeFormatted,
        ':comment' => $comment,
    ]);

    $supplierOrderId = (int)$db->lastInsertId();

    $insertList = $db->prepare(
        'INSERT INTO supplier_order_list (supplier_order_id, order_id, goods_id, quantity, price)
         VALUES (:supplier_order_id, :order_id, :goods_id, :quantity, :price)'
    );

    foreach ($items as $entry) {
        $insertList->execute([
            ':supplier_order_id' => $supplierOrderId,
            ':order_id' => $entry['order_id'],
            ':goods_id' => $entry['goods_id'],
            ':quantity' => $entry['quantity'],
            ':price' => $entry['price'],
        ]);
    }

    $db->commit();

    $respond(201, [
        'status' => 'success',
        'message' => 'Supplier order created successfully',
        'supplierOrderId' => $supplierOrderId,
    ]);
} catch (PDOException $exception) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Supplier order creation failed: ' . $exception->getMessage());
    $respond(500, ['status' => 'error', 'message' => 'Database error while creating supplier order']);
} catch (Throwable $throwable) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Supplier order unexpected failure: ' . $throwable->getMessage());
    $respond(500, ['status' => 'error', 'message' => 'Unexpected server error while creating supplier order']);
}