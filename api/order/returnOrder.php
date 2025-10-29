<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

require_once '../../config/Database.php';

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, [
        'status' => 'error',
        'message' => 'Method not allowed'
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, [
        'status' => 'error',
        'message' => 'Unable to read request body'
    ]);
}

$data = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    $respond(400, [
        'status' => 'error',
        'message' => 'Invalid JSON payload'
    ]);
}

$requiredFields = ['orderId', 'customerId', 'totalPrice', 'isComfirmed', 'returnOrderlist'];
foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, [
            'status' => 'error',
            'message' => "Missing field: {$field}"
        ]);
    }
}

$orderId = filter_var($data['orderId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false) {
    $respond(422, [
        'status' => 'error',
        'message' => 'orderId must be a positive integer'
    ]);
}

$customerId = filter_var($data['customerId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($customerId === false) {
    $respond(422, [
        'status' => 'error',
        'message' => 'customerId must be a positive integer'
    ]);
}

if (!is_numeric($data['totalPrice'])) {
    $respond(422, [
        'status' => 'error',
        'message' => 'totalPrice must be numeric'
    ]);
}
$totalPrice = round((float) $data['totalPrice'], 2);

$isComfirmedRaw = $data['isComfirmed'];
if (is_bool($isComfirmedRaw)) {
    $isComfirmed = $isComfirmedRaw ? 1 : 0;
} elseif (in_array($isComfirmedRaw, [0, 1, '0', '1'], true)) {
    $isComfirmed = (int) $isComfirmedRaw;
} else {
    $respond(422, [
        'status' => 'error',
        'message' => 'isComfirmed must be boolean or 0/1'
    ]);
}

$returnOrderlist = $data['returnOrderlist'];
if (!is_array($returnOrderlist) || $returnOrderlist === []) {
    $respond(422, [
        'status' => 'error',
        'message' => 'returnOrderlist must be a non-empty array'
    ]);
}

$cleanItems = [];
foreach ($returnOrderlist as $index => $item) {
    if (!is_array($item)) {
        $respond(422, [
            'status' => 'error',
            'message' => "returnOrderlist[{$index}] must be an object"
        ]);
    }

    foreach (['orderListId', 'quantity', 'price'] as $itemField) {
        if (!array_key_exists($itemField, $item)) {
            $respond(422, [
                'status' => 'error',
                'message' => "Missing field in returnOrderlist[{$index}]: {$itemField}"
            ]);
        }
    }

    $orderListId = filter_var($item['orderListId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($orderListId === false) {
        $respond(422, [
            'status' => 'error',
            'message' => "orderListId in returnOrderlist[{$index}] must be a positive integer"
        ]);
    }

    $quantity = filter_var($item['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($quantity === false) {
        $respond(422, [
            'status' => 'error',
            'message' => "quantity in returnOrderlist[{$index}] must be a positive integer"
        ]);
    }

    if (!is_numeric($item['price'])) {
        $respond(422, [
            'status' => 'error',
            'message' => "price in returnOrderlist[{$index}] must be numeric"
        ]);
    }
    $price = round((float) $item['price'], 2);

    $cleanItems[] = [
        'orderListId' => $orderListId,
        'quantity' => $quantity,
        'price' => $price
    ];
}

$database = null;
$db = null;

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $db->beginTransaction();

    $orderStmt = $db->prepare('SELECT customer_id FROM orders WHERE id = :orderId LIMIT 1');
    $orderStmt->execute([':orderId' => $orderId]);
    $orderRow = $orderStmt->fetch();

    if ($orderRow === false) {
        $db->rollBack();
        $respond(404, [
            'status' => 'error',
            'message' => 'Order not found',
            'orderId' => $orderId
        ]);
    }

    if ((int) $orderRow['customer_id'] !== $customerId) {
        $db->rollBack();
        $respond(409, [
            'status' => 'error',
            'message' => 'Provided customerId does not match the order',
            'orderId' => $orderId,
            'customerId' => $customerId
        ]);
    }

    $insertReturnOrder = $db->prepare(
        'INSERT INTO return_order (order_id, customer_id, total_price, is_comfirmed)
         VALUES (:orderId, :customerId, :totalPrice, :isComfirmed)'
    );
    $insertReturnOrder->bindValue(':orderId', $orderId, PDO::PARAM_INT);
    $insertReturnOrder->bindValue(':customerId', $customerId, PDO::PARAM_INT);
    $insertReturnOrder->bindValue(':totalPrice', $totalPrice);
    $insertReturnOrder->bindValue(':isComfirmed', $isComfirmed, PDO::PARAM_INT);
    $insertReturnOrder->execute();

    $returnOrderId = (int) $db->lastInsertId();

    $orderListCheck = $db->prepare('SELECT orders_id FROM ordered_list WHERE id = :orderListId LIMIT 1');
    $insertReturnItem = $db->prepare(
        'INSERT INTO returnOrderList (order_list_id, return_order_id, quantity, price)
         VALUES (:orderListId, :returnOrderId, :quantity, :price)'
    );

    foreach ($cleanItems as $item) {
        $orderListCheck->execute([':orderListId' => $item['orderListId']]);
        $orderListRow = $orderListCheck->fetch();

        if ($orderListRow === false) {
            $db->rollBack();
            $respond(404, [
                'status' => 'error',
                'message' => 'Order list item not found',
                'orderListId' => $item['orderListId']
            ]);
        }

        if ((int) $orderListRow['orders_id'] !== $orderId) {
            $db->rollBack();
            $respond(409, [
                'status' => 'error',
                'message' => 'Order list item does not belong to the specified order',
                'orderListId' => $item['orderListId'],
                'orderId' => $orderId
            ]);
        }

        $insertReturnItem->execute([
            ':orderListId' => $item['orderListId'],
            ':returnOrderId' => $returnOrderId,
            ':quantity' => $item['quantity'],
            ':price' => $item['price']
        ]);
    }

    $db->commit();

    $respond(201, [
        'status' => 'success',
        'message' => 'Return order recorded successfully',
        'returnOrderId' => $returnOrderId
    ]);
} catch (PDOException $exception) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Return order database error: ' . $exception->getMessage());
    $respond(500, [
        'status' => 'error',
        'message' => 'Database error'
    ]);
} catch (Throwable $throwable) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Return order unexpected error: ' . $throwable->getMessage());
    $respond(500, [
        'status' => 'error',
        'message' => 'Server error'
    ]);
}