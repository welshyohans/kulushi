<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: access");
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

require_once '../../config/Database.php';
require_once '../../model/SMS.php';

function normalizePhoneForSms(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return null;
    }

    if (strncmp($digits, '251', 3) === 0) {
        $digits = substr($digits, -9);
    }

    if (strlen($digits) === 9) {
        return '+251' . $digits;
    }

    if (strlen($digits) === 10 && $digits[0] === '0') {
        return '+251' . substr($digits, 1);
    }

    return null;
}

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

// replace required fields line to include supplierId
$requiredFields = ['customerId', 'supplierId', 'totalPrice', 'orderTime', 'supplierOrderLists'];
foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, ['status' => 'error', 'message' => "Missing field: {$field}"]);
    }
}

$customerId = filter_var($data['customerId'], FILTER_VALIDATE_INT);
if ($customerId === false || $customerId <= 0) {
    $respond(400, ['status' => 'error', 'message' => 'Invalid customerId']);
}

// Added supplierId validation
$supplierId = filter_var($data['supplierId'], FILTER_VALIDATE_INT);
if ($supplierId === false || $supplierId <= 0) {
    $respond(400, ['status' => 'error', 'message' => 'Invalid supplierId']);
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
$calculatedTotal = 0.0;
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

    $calculatedTotal += $quantity * $price;
}

$db = null;

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $supplierStmt = $db->prepare('SELECT shop_name, phone FROM supplier WHERE shop_id = :id LIMIT 1');
    $supplierStmt->execute([':id' => $supplierId]);
    $supplierRow = $supplierStmt->fetch(PDO::FETCH_ASSOC);
    if (!$supplierRow) {
        $respond(404, ['status' => 'error', 'message' => 'Supplier not found']);
    }

    $db->beginTransaction();

    $insertOrder = $db->prepare(
        // include supplier_id in the insert
        'INSERT INTO supplier_order (supplier_id, customer_id, total_price, order_time, comment)
         VALUES (:supplier_id, :customer_id, :total_price, :order_time, :comment)'
    );

    $insertOrder->execute([
        ':supplier_id' => $supplierId,
        ':customer_id' => $customerId,
        ':total_price' => $calculatedTotal,
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

    $responsePayload = [
        'status' => 'success',
        'message' => 'Supplier order created successfully',
        'supplierOrderId' => $supplierOrderId,
    ];

    try {
        $goodsIds = array_column($items, 'goods_id');
        $goodsMap = [];
        if (!empty($goodsIds)) {
            $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));
            $goodsStmt = $db->prepare("SELECT id, name FROM goods WHERE id IN ($placeholders)");
            $goodsStmt->execute($goodsIds);
            while ($goodsRow = $goodsStmt->fetch(PDO::FETCH_ASSOC)) {
                $goodsMap[(int)$goodsRow['id']] = $goodsRow['name'] ?? '';
            }
        }

        $summaryParts = array_map(static function (array $entry) use ($goodsMap): string {
            $name = $goodsMap[$entry['goods_id']] ?? ('Goods ' . $entry['goods_id']);
            $qty = (int)$entry['quantity'];
            $price = number_format((float)$entry['price'], 2, '.', ',');
            return sprintf('%s=%d*%s', $name, $qty, $price);
        }, $items);

        $supplierPhone = normalizePhoneForSms($supplierRow['phone'] ?? null);
        if ($supplierPhone) {
            $sms = new SMS();
            $sms->sendSms(
                $supplierPhone,
                sprintf(
                    "Merkato Pro: New shop order #%d. Total ETB %s.\n%s",
                    $supplierOrderId,
                    number_format($calculatedTotal, 2, '.', ','),
                    implode("\n", $summaryParts)
                )
            );
        }
    } catch (Throwable $smsError) {
        error_log('Supplier order SMS failed: ' . $smsError->getMessage());
    }

    $respond(201, $responsePayload);
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
