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
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, [
        'success' => false,
        'message' => 'Unable to read request body.',
    ]);
}

$data = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    $respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.',
    ]);
}

if (!array_key_exists('supplierId', $data)) {
    $respond(400, [
        'success' => false,
        'message' => 'Missing field: supplierId.',
    ]);
}

$supplierId = filter_var(
    $data['supplierId'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($supplierId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'supplierId must be a positive integer.',
    ]);
}

if (!array_key_exists('date', $data) || trim((string)$data['date']) === '') {
    $respond(400, [
        'success' => false,
        'message' => 'Missing or empty field: date.',
    ]);
}

$dateRaw = trim((string)$data['date']);
$timestamp = strtotime($dateRaw);
if ($timestamp === false) {
    $respond(400, [
        'success' => false,
        'message' => 'Invalid date format. Please provide a recognizable date.',
    ]);
}

$orderDate = date('Y-m-d', $timestamp);

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $supplierCheck = $db->prepare('SELECT 1 FROM supplier WHERE shop_id = :id LIMIT 1');
    $supplierCheck->execute([':id' => $supplierId]);
    if ($supplierCheck->fetchColumn() === false) {
        $respond(404, [
            'success' => false,
            'message' => 'Supplier not found.',
        ]);
    }

    $stmt = $db->prepare(
        'SELECT
            o.id AS order_id,
            COALESCE(c.name, c.shop_name, "Unknown Customer") AS customer_name,
            o.total_price,
            c.phone,
            c.specific_address,
            c.location,
            o.comment,
            ol.id AS order_list_id,
            ol.goods_id,
            COALESCE(g.name, "Unknown Product") AS goods_name,
            COALESCE(ol.each_price, 0) AS price,
            COALESCE(ol.quantity, 0) AS quantity,
            COALESCE(ol.status, 0) AS status,
            g.image_url AS image_url
        FROM ordered_list ol
        INNER JOIN supplier_goods sg ON sg.id = ol.supplier_goods_id
        INNER JOIN orders o ON o.id = ol.orders_id
        LEFT JOIN customer c ON c.id = o.customer_id
        LEFT JOIN goods g ON g.id = ol.goods_id
        WHERE sg.supplier_id = :supplierId
          AND DATE(o.order_time) = :orderDate
        ORDER BY o.order_time DESC, o.id DESC, ol.id ASC'
    );
    $stmt->execute([
        ':supplierId' => $supplierId,
        ':orderDate' => $orderDate,
    ]);

    $rows = $stmt->fetchAll();
    $orders = [];

    foreach ($rows as $row) {
        $orderId = isset($row['order_id']) ? (int)$row['order_id'] : 0;
        if ($orderId === 0) {
            continue;
        }

        if (!isset($orders[$orderId])) {
            $orders[$orderId] = [
                'orderId' => $orderId,
                'customerName' => $row['customer_name'] ?? 'Unknown Customer',
                'totalPrice' => isset($row['total_price']) ? (float)$row['total_price'] : 0.0,
                'phone' => $row['phone'] ?? null,
                'address' => $row['specific_address'] ?? ($row['location'] ?? null),
                'comment' => $row['comment'] ?? null,
                'ordrList' => [],
            ];
        }

        $orders[$orderId]['ordrList'][] = [
            'orderListId' => isset($row['order_list_id']) ? (int)$row['order_list_id'] : null,
            'goodsId' => isset($row['goods_id']) ? (int)$row['goods_id'] : 0,
            'goodsName' => $row['goods_name'] ?? '',
            'price' => isset($row['price']) ? (float)$row['price'] : 0.0,
            'quantity' => isset($row['quantity']) ? (float)$row['quantity'] : 0.0,
            'status' => isset($row['status']) ? (int)$row['status'] : 0,
            'imageUrl' => $row['image_url'] ?? null,
        ];
    }

    $payload = [
        'success' => true,
        'orders' => array_values($orders),
    ];

    if (empty($payload['orders'])) {
        $payload['message'] = 'No orders found for the provided supplier and date.';
    }

    $respond(200, $payload);
} catch (PDOException $exception) {
    error_log('Database Error: ' . $exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => 'A database error occurred while retrieving supplier orders.',
    ]);
} catch (Throwable $exception) {
    error_log('Server Error: ' . $exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => 'An unexpected server error occurred while retrieving supplier orders.',
    ]);
}

?>
