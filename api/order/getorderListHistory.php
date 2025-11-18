<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

require_once '../../config/Database.php';

$respond = function (int $status, array $payload): void {
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

if (!array_key_exists('orderId', $data)) {
    $respond(400, [
        'status' => 'error',
        'message' => 'Missing field: orderId'
    ]);
}

$orderId = filter_var($data['orderId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false) {
    $respond(422, [
        'status' => 'error',
        'message' => 'orderId must be a positive integer'
    ]);
}

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $orderCheck = $db->prepare('SELECT id FROM orders WHERE id = :id LIMIT 1');
    $orderCheck->execute([':id' => $orderId]);
    if ($orderCheck->fetchColumn() === false) {
        $respond(404, [
            'status' => 'error',
            'message' => 'Order not found',
            'orderId' => $orderId
        ]);
    }

    $itemsStmt = $db->prepare(
        'SELECT
            g.name AS goods_name,
            g.image_url AS goods_image,
            ol.id AS order_list_id,
            ol.quantity,
            COALESCE(ol.each_price, sg.price) AS unit_price,
            s.shop_name AS supplier_name,
            ol.eligible_for_credit
        FROM ordered_list ol
        LEFT JOIN goods g ON g.id = ol.goods_id
        LEFT JOIN supplier_goods sg ON sg.id = ol.supplier_goods_id
        LEFT JOIN supplier s ON s.shop_id = sg.supplier_id
        WHERE ol.orders_id = :orderId AND ol.status != -1
        ORDER BY ol.id ASC'
    );
    $itemsStmt->execute([':orderId' => $orderId]);
    $rows = $itemsStmt->fetchAll();

    $items = array_map(static function (array $row): array {
        return [
            'goodsName' => $row['goods_name'] ?? '',
            'goodsImage' => $row['goods_image'] ?? '',
            'orderListId' => $row['order_list_id'] !== null ? (string)(int)$row['order_list_id'] : '0',
            'quantity' => $row['quantity'] !== null ? (string)(int)$row['quantity'] : '0',
            'price' => $row['unit_price'] !== null ? (string)(float)$row['unit_price'] : '0',
            'supplierName' => $row['supplier_name'] ?? '',
            'eligibleForCredit' => isset($row['eligible_for_credit']) ? (bool)$row['eligible_for_credit'] : false
        ];
    }, $rows);

    // Return raw array (no wrapper) for the Android client
    http_response_code(200);
    echo json_encode($items);
    exit;
} catch (PDOException $exception) {
    $respond(500, [
        'status' => 'error',
        'message' => 'Database error',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    $respond(500, [
        'status' => 'error',
        'message' => 'Server error',
        'error' => $throwable->getMessage()
    ]);
}
