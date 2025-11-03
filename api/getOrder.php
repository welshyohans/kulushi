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

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $stmt = $db->query(
        'SELECT
            o.id AS order_id,
            o.customer_id,
            c.name AS customer_name,
            c.phone AS customer_phone,
            o.total_price,
            o.profit,
            o.order_time,
            o.deliver_status,
            o.comment,
            COALESCE(COUNT(DISTINCT ol.id), 0) AS item_count,
            COALESCE(SUM(ol.quantity), 0) AS total_quantity
        FROM orders o
        LEFT JOIN customer c ON c.id = o.customer_id
        LEFT JOIN ordered_list ol ON ol.orders_id = o.id
        GROUP BY o.id
        ORDER BY o.order_time DESC'
    );

    $orders = [];
    while ($row = $stmt->fetch()) {
        $orders[] = [
            'orderId' => (int)$row['order_id'],
            'customerId' => $row['customer_id'] !== null ? (int)$row['customer_id'] : null,
            'customerName' => $row['customer_name'] ?? '',
            'customerPhone' => $row['customer_phone'] ?? '',
            'totalPrice' => $row['total_price'] !== null ? (float)$row['total_price'] : 0.0,
            'profit' => $row['profit'] !== null ? (float)$row['profit'] : 0.0,
            'orderTime' => $row['order_time'],
            'deliverStatus' => $row['deliver_status'] !== null ? (int)$row['deliver_status'] : null,
            'comment' => $row['comment'] ?? '',
            'itemCount' => (int)$row['item_count'],
            'totalQuantity' => (int)$row['total_quantity']
        ];
    }

    $respond(200, [
        'success' => true,
        'orders' => $orders,
        'count' => count($orders)
    ]);
} catch (PDOException $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Database error while fetching orders.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}