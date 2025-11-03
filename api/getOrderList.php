<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

require_once __DIR__ . '/../config/Database.php';

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $respond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
}

$orderId = filter_input(INPUT_GET, 'orderId', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($orderId === false || $orderId === null) {
    $respond(400, [
        'success' => false,
        'message' => 'Missing or invalid orderId parameter.'
    ]);
}

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $orderExistsStmt = $db->prepare('SELECT id FROM orders WHERE id = :orderId LIMIT 1');
    $orderExistsStmt->execute([':orderId' => $orderId]);

    if ($orderExistsStmt->fetchColumn() === false) {
        $respond(404, [
            'success' => false,
            'message' => 'Order not found.',
            'orderId' => $orderId
        ]);
    }

    $itemsStmt = $db->prepare(
        'SELECT
            ol.id AS order_list_id,
            COALESCE(g.name, "Unknown Item") AS goods_name,
            g.image_url AS goods_image,
            COALESCE(ol.quantity, 0) AS quantity,
            COALESCE(ol.each_price, sg.price, 0) AS unit_price,
            COALESCE(s.shop_name, "Unknown Supplier") AS supplier_name,
            COALESCE(ol.eligible_for_credit, 0) AS eligible_for_credit
        FROM ordered_list ol
        LEFT JOIN goods g ON g.id = ol.goods_id
        LEFT JOIN supplier_goods sg ON sg.id = ol.supplier_goods_id
        LEFT JOIN supplier s ON s.shop_id = sg.supplier_id
        WHERE ol.orders_id = :orderId
        ORDER BY ol.id ASC'
    );

    $itemsStmt->execute([':orderId' => $orderId]);

    $items = [];
    while ($row = $itemsStmt->fetch()) {
        $quantity = (int)$row['quantity'];
        $unitPrice = (float)$row['unit_price'];

        $items[] = [
            'orderListId' => (int)$row['order_list_id'],
            'goodsName' => $row['goods_name'],
            'goodsImage' => $row['goods_image'] ?? '',
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
            'lineTotal' => $quantity * $unitPrice,
            'supplierName' => $row['supplier_name'],
            'eligibleForCredit' => (bool)$row['eligible_for_credit']
        ];
    }

    $respond(200, [
        'success' => true,
        'items' => $items,
        'count' => count($items)
    ]);
} catch (PDOException $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Database error while fetching ordered items.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}