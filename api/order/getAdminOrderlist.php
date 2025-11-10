<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

require_once __DIR__ . '/../../config/Database.php';

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.',
        'orderList' => [],
        'suppliers' => []
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, [
        'success' => false,
        'message' => 'Unable to read request body.',
        'orderList' => [],
        'suppliers' => []
    ]);
}

$data = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    $respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.',
        'orderList' => [],
        'suppliers' => []
    ]);
}

if (!array_key_exists('orderId', $data)) {
    $respond(400, [
        'success' => false,
        'message' => 'Missing field: orderId.',
        'orderList' => [],
        'suppliers' => []
    ]);
}

$orderId = filter_var($data['orderId'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);
if ($orderId === false || $orderId === null) {
    $respond(422, [
        'success' => false,
        'message' => 'orderId must be a positive integer.',
        'orderList' => [],
        'suppliers' => []
    ]);
}

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $orderCheck = $db->prepare('SELECT id FROM orders WHERE id = :orderId LIMIT 1');
    $orderCheck->execute([':orderId' => $orderId]);
    if ($orderCheck->fetchColumn() === false) {
        $respond(404, [
            'success' => false,
            'message' => 'Order not found.',
            'orderList' => [],
            'suppliers' => []
        ]);
    }

    $itemsStmt = $db->prepare(
        'SELECT
            ol.id AS order_list_id,
            ol.orders_id AS order_id,
            ol.goods_id,
            ol.supplier_goods_id,
            COALESCE(ol.each_price, sg.price, 0) AS unit_price,
            COALESCE(ol.quantity, 0) AS quantity,
            COALESCE(ol.status, 0) AS status,
            COALESCE(ol.eligible_for_credit, 0) AS eligible_for_credit,
            g.name AS goods_name,
            g.image_url AS goods_image,
            sg.supplier_id,
            s.shop_name AS supplier_name
        FROM ordered_list ol
        LEFT JOIN goods g ON g.id = ol.goods_id
        LEFT JOIN supplier_goods sg ON sg.id = ol.supplier_goods_id
        LEFT JOIN supplier s ON s.shop_id = sg.supplier_id
        WHERE ol.orders_id = :orderId
        ORDER BY ol.id ASC'
    );
    $itemsStmt->execute([':orderId' => $orderId]);
    $rows = $itemsStmt->fetchAll();

    $orderList = [];
    $suppliersMap = [];
    foreach ($rows as $row) {
        $supplierId = isset($row['supplier_id']) ? (int)$row['supplier_id'] : 0;
        if ($supplierId > 0 && !isset($suppliersMap[$supplierId])) {
            $suppliersMap[$supplierId] = [
                'supplierId' => $supplierId,
                'supplierName' => $row['supplier_name'] ?? 'Unknown Supplier'
            ];
        }

        $orderList[] = [
            'orderListId' => (int)$row['order_list_id'],
            'orderId' => (int)$row['order_id'],
            'supplierId' => $supplierId,
            'goodsName' => $row['goods_name'] ?? 'Unknown Item',
            'goodsId' => isset($row['goods_id']) ? (int)$row['goods_id'] : 0,
            'supplierGoodsId' => $row['supplier_goods_id'] !== null ? (int)$row['supplier_goods_id'] : null,
            'price' => (float)$row['unit_price'],
            'quantity' => (float)$row['quantity'],
            'status' => (int)$row['status'],
            'eligibleForCredit' => (int)$row['eligible_for_credit'],
            'imageUrl' => $row['goods_image'] ?? null,
        ];
    }

    $suppliers = array_values($suppliersMap);

    $payload = [
        'success' => true,
        'orderList' => $orderList,
        'suppliers' => $suppliers,
    ];

    if (empty($orderList)) {
        $payload['message'] = 'No order list items were found for this order.';
    }

    $respond(200, $payload);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => 'Database error while retrieving order list.',
        'orderList' => [],
        'suppliers' => []
    ]);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error while retrieving order list.',
        'orderList' => [],
        'suppliers' => []
    ]);
}
