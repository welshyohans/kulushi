<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, [
        'success' => false,
        'message' => 'Unable to read request body.'
    ]);
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    $respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.'
    ]);
}

foreach (['orderListId', 'status'] as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, [
            'success' => false,
            'message' => "Missing field: {$field}"
        ]);
    }
}

$orderListId = filter_var(
    $data['orderListId'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($orderListId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'orderListId must be a positive integer.'
    ]);
}

$status = filter_var(
    $data['status'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => -128, 'max_range' => 127]]
);
if ($status === false) {
    $respond(422, [
        'success' => false,
        'message' => 'status must be an integer between -128 and 127.'
    ]);
}

$isAvailableRaw = $data['isAvailable'] ?? ($data['is_available'] ?? 1);
$isAvailable = filter_var(
    $isAvailableRaw,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 1]]
);
if ($isAvailable === false) {
    $respond(422, [
        'success' => false,
        'message' => 'isAvailable must be either 0 or 1.'
    ]);
}

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../model/Settings.php';
require_once __DIR__ . '/../../model/SupplierGoods.php';

try {
    $database = new Database();
    $db = $database->connect();
    if (!$db instanceof PDO) {
        $respond(500, [
            'success' => false,
            'message' => 'Unable to establish database connection.'
        ]);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $db->beginTransaction();

    $selectStmt = $db->prepare(
        'SELECT id, orders_id, supplier_goods_id, goods_id
         FROM ordered_list
         WHERE id = :id
         LIMIT 1 FOR UPDATE'
    );
    $selectStmt->execute([':id' => $orderListId]);
    $orderListRow = $selectStmt->fetch();

    if ($orderListRow === false) {
        $db->rollBack();
        $respond(404, [
            'success' => false,
            'message' => 'Order list item not found.',
            'orderListId' => $orderListId
        ]);
    }

    $ordersId = (int)$orderListRow['orders_id'];
    $supplierGoodsId = $orderListRow['supplier_goods_id'] !== null
        ? (int)$orderListRow['supplier_goods_id']
        : null;
    $goodsId = $orderListRow['goods_id'] !== null
        ? (int)$orderListRow['goods_id']
        : null;

    $updateStmt = $db->prepare(
        'UPDATE ordered_list SET status = :status WHERE id = :id'
    );
    $updateStmt->execute([
        ':status' => $status,
        ':id' => $orderListId
    ]);

    $totalStmt = $db->prepare(
        'SELECT COALESCE(SUM(each_price * quantity), 0) AS total
         FROM ordered_list
         WHERE orders_id = :orderId
           AND status != -1'
    );
    $totalStmt->execute([':orderId' => $ordersId]);
    $newTotal = (float)$totalStmt->fetchColumn();

    $orderUpdateStmt = $db->prepare(
        'UPDATE orders SET total_price = :totalPrice WHERE id = :orderId'
    );
    $orderUpdateStmt->execute([
        ':totalPrice' => $newTotal,
        ':orderId' => $ordersId
    ]);

    if ($isAvailable === 0) {
        $settings = new Settings($db);
        $supplierGoodsModel = new SupplierGoods($db);
        $newLastUpdateCode = (int)$settings->nextCode();

        if ($supplierGoodsId !== null) {
            $supplierGoodsModel->updateAvailabilityById(
                $supplierGoodsId,
                0,
                $newLastUpdateCode
            );
        }

        if ($goodsId !== null) {
            $goodsUpdateStmt = $db->prepare(
                'UPDATE goods SET last_update_code = :code WHERE id = :id'
            );
            $goodsUpdateStmt->execute([
                ':code' => $newLastUpdateCode,
                ':id' => $goodsId
            ]);
        }
    }

    $db->commit();

    $message = sprintf(
        'Order list %d status set to %d; order total recalculated to %.2f.',
        $orderListId,
        $status,
        $newTotal
    );
    if ($isAvailable === 0) {
        $message .= ' Supplier goods marked unavailable and codes refreshed.';
    }

    $respond(200, [
        'success' => true,
        'message' => $message
    ]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Database error while updating order status.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}
