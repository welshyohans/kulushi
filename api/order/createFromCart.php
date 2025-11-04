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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload.'
    ]);
    exit;
}

if (!isset($payload['customerId'], $payload['items']) || !is_array($payload['items'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'customerId and items are required.'
    ]);
    exit;
}

$customerId = (int)$payload['customerId'];
if ($customerId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'customerId must be a positive integer.'
    ]);
    exit;
}

$items = $payload['items'];
if (count($items) === 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Cart items cannot be empty.'
    ]);
    exit;
}

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $db->beginTransaction();

    $customerStmt = $db->prepare('SELECT id, name FROM customer WHERE id = :customerId LIMIT 1');
    $customerStmt->execute([':customerId' => $customerId]);
    $customer = $customerStmt->fetch();
    if (!$customer) {
        throw new RuntimeException('Customer profile not found.');
    }

    $preparedItems = [];
    $totalPrice = 0.0;
    $totalQuantity = 0;

    $offerStmt = $db->prepare(
        'SELECT
            sg.id AS supplier_goods_id,
            sg.supplier_id,
            sg.goods_id,
            sg.price,
            sg.discount_price,
            sg.discount_start,
            sg.min_order,
            sg.is_available,
            s.shop_name,
            s.shop_type,
            g.name AS goods_name
        FROM supplier_goods sg
        INNER JOIN supplier s ON s.shop_id = sg.supplier_id
        INNER JOIN goods g ON g.id = sg.goods_id
        WHERE sg.id = :supplierGoodsId
        LIMIT 1'
    );

    foreach ($items as $index => $item) {
        if (!is_array($item) || !isset($item['supplierGoodsId'], $item['quantity'])) {
            throw new RuntimeException("Invalid item at index {$index}.");
        }

        $supplierGoodsId = (int)$item['supplierGoodsId'];
        $quantity = (int)$item['quantity'];
        if ($supplierGoodsId <= 0 || $quantity <= 0) {
            throw new RuntimeException("Invalid supplierGoodsId or quantity at index {$index}.");
        }

        $offerStmt->execute([':supplierGoodsId' => $supplierGoodsId]);
        $offer = $offerStmt->fetch();
        if (!$offer) {
            throw new RuntimeException("Supplier goods #{$supplierGoodsId} not found.");
        }

        if ((int)$offer['is_available'] !== 1) {
            throw new RuntimeException("Goods '{$offer['goods_name']}' is currently unavailable.");
        }

        if (strtolower($offer['shop_type']) === 'self-delivered') {
            throw new RuntimeException("Goods '{$offer['goods_name']}' is self-delivered. Visit the supplier shop to order.");
        }

        $minOrder = (int)$offer['min_order'];
        if ($quantity < $minOrder) {
            throw new RuntimeException("Goods '{$offer['goods_name']}' requires a minimum order of {$minOrder}.");
        }

        $unitPrice = isset($offer['discount_price']) && (int)$offer['discount_price'] > 0
            ? (float)$offer['discount_price']
            : (float)$offer['price'];

        $lineTotal = $unitPrice * $quantity;
        $totalPrice += $lineTotal;
        $totalQuantity += $quantity;

        $preparedItems[] = [
            'supplier_goods_id' => $supplierGoodsId,
            'supplier_id' => (int)$offer['supplier_id'],
            'goods_id' => (int)$offer['goods_id'],
            'goods_name' => $offer['goods_name'],
            'min_order' => $minOrder,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => $lineTotal
        ];
    }

    $orderStmt = $db->prepare(
        'INSERT INTO orders (customer_id, total_price, profit, unpaid_cash, unpaid_credit, cash_amount, credit_amount, deliver_status, comment)
         VALUES (:customer_id, :total_price, 0, 0, 0, :cash_amount, 0, 1, :comment)'
    );
    $comment = sprintf('Auto order via web — items: %d, quantity: %d', count($preparedItems), $totalQuantity);
    $orderStmt->execute([
        ':customer_id' => $customerId,
        ':total_price' => $totalPrice,
        ':cash_amount' => $totalPrice,
        ':comment' => $comment
    ]);
    $orderId = (int)$db->lastInsertId();

    $orderListStmt = $db->prepare(
        'INSERT INTO ordered_list (orders_id, supplier_goods_id, goods_id, quantity, each_price, eligible_for_credit, status)
         VALUES (:orders_id, :supplier_goods_id, :goods_id, :quantity, :each_price, :eligible_for_credit, 1)'
    );

    foreach ($preparedItems as $prepared) {
        $orderListStmt->execute([
            ':orders_id' => $orderId,
            ':supplier_goods_id' => $prepared['supplier_goods_id'],
            ':goods_id' => $prepared['goods_id'],
            ':quantity' => $prepared['quantity'],
            ':each_price' => $prepared['unit_price'],
            ':eligible_for_credit' => 0
        ]);
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Order submitted successfully.',
        'orderId' => $orderId,
        'totalPrice' => $totalPrice,
        'itemCount' => count($preparedItems),
        'totalQuantity' => $totalQuantity
    ]);
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage()
    ]);
}