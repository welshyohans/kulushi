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
    $respond(405, ['success' => false, 'message' => 'Method not allowed. Use POST.']);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, ['success' => false, 'message' => 'Unable to read request body.']);
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    $respond(400, ['success' => false, 'message' => 'Invalid JSON payload.']);
}

$requiredFields = ['supplierId', 'goodsId', 'orderId', 'price', 'quantity'];
foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, ['success' => false, 'message' => "Missing field: {$field}"]);
    }
}

$supplierId = filter_var($data['supplierId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$goodsId = filter_var($data['goodsId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$orderId = filter_var($data['orderId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$quantity = filter_var($data['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($supplierId === false || $goodsId === false || $orderId === false) {
    $respond(422, ['success' => false, 'message' => 'supplierId, goodsId and orderId must be positive integers.']);
}

if ($quantity === false) {
    $respond(422, ['success' => false, 'message' => 'quantity must be a positive integer.']);
}

$priceRaw = $data['price'];
if (!is_numeric($priceRaw)) {
    $respond(422, ['success' => false, 'message' => 'price must be a number.']);
}

$price = (float)$priceRaw;
if ($price < 0) {
    $respond(422, ['success' => false, 'message' => 'price must not be negative.']);
}

$lineTotal = round($price * $quantity, 2);

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, ['success' => false, 'message' => 'Unable to establish database connection.']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $db->beginTransaction();

    $supplierCheck = $db->prepare('SELECT 1 FROM supplier WHERE shop_id = :supplierId LIMIT 1');
    $supplierCheck->execute([':supplierId' => $supplierId]);
    if (!$supplierCheck->fetchColumn()) {
        $db->rollBack();
        $respond(404, ['success' => false, 'message' => 'Supplier not found.']);
    }

    $goodsCheck = $db->prepare('SELECT 1 FROM goods WHERE id = :goodsId LIMIT 1');
    $goodsCheck->execute([':goodsId' => $goodsId]);
    if (!$goodsCheck->fetchColumn()) {
        $db->rollBack();
        $respond(404, ['success' => false, 'message' => 'Goods not found.']);
    }

    $orderStmt = $db->prepare('SELECT total_price FROM orders WHERE id = :orderId FOR UPDATE');
    $orderStmt->execute([':orderId' => $orderId]);
    $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if ($orderRow === false) {
        $db->rollBack();
        $respond(404, ['success' => false, 'message' => 'Order not found.']);
    }

    $supplierGoodsStmt = $db->prepare('SELECT id FROM supplier_goods WHERE supplier_id = :supplierId AND goods_id = :goodsId LIMIT 1');
    $supplierGoodsStmt->execute([
        ':supplierId' => $supplierId,
        ':goodsId' => $goodsId
    ]);

    $supplierGoodsRow = $supplierGoodsStmt->fetch(PDO::FETCH_ASSOC);
    if ($supplierGoodsRow !== false) {
        $supplierGoodsId = (int)$supplierGoodsRow['id'];
    } else {
        $insertSupplierGoods = $db->prepare(
            'INSERT INTO supplier_goods 
             (supplier_id, goods_id, price, discount_start, discount_price, min_order, is_available_for_credit, is_available, last_update_code)
             VALUES 
             (:supplierId, :goodsId, :price, 0, :discountPrice, 1, 0, 1, 0)'
        );
        $insertSupplierGoods->execute([
            ':supplierId' => $supplierId,
            ':goodsId' => $goodsId,
            ':price' => (int)round($price),
            ':discountPrice' => (int)round($price)
        ]);
        $supplierGoodsId = (int)$db->lastInsertId();
    }

    $insertOrdered = $db->prepare(
        'INSERT INTO ordered_list 
         (orders_id, supplier_goods_id, goods_id, quantity, each_price, eligible_for_credit, status)
         VALUES
         (:ordersId, :supplierGoodsId, :goodsId, :quantity, :eachPrice, 0, 0)'
    );
    $insertOrdered->execute([
        ':ordersId' => $orderId,
        ':supplierGoodsId' => $supplierGoodsId,
        ':goodsId' => $goodsId,
        ':quantity' => $quantity,
        ':eachPrice' => $price
    ]);

    $currentTotal = $orderRow['total_price'] !== null ? (float)$orderRow['total_price'] : 0.0;
    $newTotal = round($currentTotal + $lineTotal, 2);
    $updateOrder = $db->prepare('UPDATE orders SET total_price = :totalPrice WHERE id = :orderId');
    $updateOrder->execute([
        ':totalPrice' => number_format($newTotal, 2, '.', ''),
        ':orderId' => $orderId
    ]);

    $db->commit();

    $respond(200, ['success' => true, 'message' => 'Manual order item recorded.']);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Database error while adding manual order item.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected error while adding manual order item.',
        'error' => $throwable->getMessage()
    ]);
}
