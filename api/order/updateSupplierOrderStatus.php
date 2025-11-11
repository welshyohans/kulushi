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

foreach (['supplierId', 'goodsId', 'orderListIds', 'status'] as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, [
            'success' => false,
            'message' => "Missing field: {$field}"
        ]);
    }
}

$supplierId = filter_var($data['supplierId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($supplierId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'supplierId must be a positive integer.'
    ]);
}

$goodsId = filter_var($data['goodsId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($goodsId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'goodsId must be a positive integer.'
    ]);
}

$orderListIdsRaw = $data['orderListIds'];
if (!is_array($orderListIdsRaw)) {
    $respond(422, [
        'success' => false,
        'message' => 'orderListIds must be an array of integers.'
    ]);
}

$validatedOrderListIds = [];
foreach ($orderListIdsRaw as $index => $maybeId) {
    $validated = filter_var(
        $maybeId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($validated === false) {
        $respond(422, [
            'success' => false,
            'message' => "orderListIds[{$index}] must be a positive integer."
        ]);
    }
    $validatedOrderListIds[] = $validated;
}

$validatedOrderListIds = array_values(array_unique($validatedOrderListIds));
if ($validatedOrderListIds === []) {
    $respond(422, [
        'success' => false,
        'message' => 'orderListIds must contain at least one unique positive integer.'
    ]);
}

$status = filter_var(
    $data['status'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 127]]
);
if ($status === false) {
    $respond(422, [
        'success' => false,
        'message' => 'status must be an integer between 0 and 127.'
    ]);
}

require_once __DIR__ . '/../../config/Database.php';
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

    $supplierGoodsModel = new SupplierGoods($db);
    $relation = $supplierGoodsModel->findBySupplierAndGoods($supplierId, $goodsId);
    if ($relation === null) {
        $respond(404, [
            'success' => false,
            'message' => 'No supplier goods relation found for the supplied identifiers.',
            'supplierId' => $supplierId,
            'goodsId' => $goodsId
        ]);
    }

    $supplierGoodsId = isset($relation['id']) ? (int)$relation['id'] : 0;
    if ($supplierGoodsId <= 0) {
        $respond(500, [
            'success' => false,
            'message' => 'Invalid supplier goods record.'
        ]);
    }

    $inPlaceholders = [];
    $inParams = [];
    foreach ($validatedOrderListIds as $idx => $orderListId) {
        $placeholder = ':orderListId' . $idx;
        $inPlaceholders[] = $placeholder;
        $inParams[$placeholder] = $orderListId;
    }
    $inClause = implode(', ', $inPlaceholders);

    $selectSql = <<<SQL
SELECT id, orders_id
FROM ordered_list
WHERE goods_id = :goodsId
  AND supplier_goods_id = :supplierGoodsId
  AND id IN ({$inClause})
FOR UPDATE
SQL;

    $selectStmt = $db->prepare($selectSql);
    $baseParams = [
        ':goodsId' => $goodsId,
        ':supplierGoodsId' => $supplierGoodsId
    ];

    $db->beginTransaction();

    $selectStmt->execute(array_merge($inParams, $baseParams));
    $matchedRows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($matchedRows === false || count($matchedRows) === 0) {
        $db->rollBack();
        $respond(404, [
            'success' => false,
            'message' => 'No supplier order items were found for the provided orderListIds.',
            'orderListIds' => $validatedOrderListIds,
            'supplierId' => $supplierId,
            'goodsId' => $goodsId
        ]);
    }

    $matchingCount = count($matchedRows);

    $ordersToRecalc = [];
    foreach ($matchedRows as $row) {
        if (!empty($row['orders_id'])) {
            $ordersToRecalc[] = (int)$row['orders_id'];
        }
    }

    $updateSql = <<<SQL
UPDATE ordered_list
SET status = :status
WHERE goods_id = :goodsId
  AND supplier_goods_id = :supplierGoodsId
  AND id IN ({$inClause})
SQL;

    $updateStmt = $db->prepare($updateSql);
    $updateParams = array_merge($inParams, $baseParams, [':status' => $status]);
    $updateStmt->execute($updateParams);
    $affectedRows = $updateStmt->rowCount();

    $recalculatedOrders = [];
    if ($status === -1 && $ordersToRecalc !== []) {
        $ordersToRecalc = array_values(array_unique($ordersToRecalc));

        $totalStmt = $db->prepare(
            'SELECT COALESCE(SUM(each_price * quantity), 0) AS total
             FROM ordered_list
             WHERE orders_id = :orderId
               AND status != -1'
        );
        $orderUpdateStmt = $db->prepare(
            'UPDATE orders SET total_price = :totalPrice WHERE id = :orderId'
        );

        foreach ($ordersToRecalc as $orderId) {
            $totalStmt->execute([':orderId' => $orderId]);
            $newTotal = (float)$totalStmt->fetchColumn();
            $orderUpdateStmt->execute([
                ':totalPrice' => $newTotal,
                ':orderId' => $orderId
            ]);
            $recalculatedOrders[$orderId] = $newTotal;
        }
    }

    $db->commit();

    $message = $affectedRows > 0
        ? "Updated status for {$affectedRows} supplier order item" . ($affectedRows === 1 ? '' : 's') . '.'
        : 'Status already matches the requested value for the matched items.';

    $response = [
        'success' => true,
        'message' => $message,
        'supplierId' => $supplierId,
        'goodsId' => $goodsId,
        'orderListIds' => $validatedOrderListIds,
        'status' => $status,
        'matchedCount' => $matchingCount,
        'affectedCount' => $affectedRows
    ];

    if ($recalculatedOrders !== []) {
        $response['recalculatedOrderTotals'] = $recalculatedOrders;
    }

    $respond(200, $response);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Database error while updating supplier order statuses.',
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
