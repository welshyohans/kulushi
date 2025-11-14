<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

require_once __DIR__ . '/../../config/Database.php';

/**
 * Sends a JSON response.
 *
 * @param int $status The HTTP status code.
 * @param array $payload The response payload.
 */
function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.',
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    respond(400, [
        'success' => false,
        'message' => 'Unable to read request body.',
    ]);
}

$data = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.',
    ]);
}

if (!isset($data['date']) || empty(trim($data['date']))) {
    respond(400, [
        'success' => false,
        'message' => 'Missing or empty field: date.',
    ]);
}

$dateRaw = trim($data['date']);
$timestamp = strtotime($dateRaw);

if ($timestamp === false) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid date format. Please provide a recognizable date format.',
    ]);
}

// Format the date to 'YYYY-MM-DD' for SQL comparison
$formattedDate = date('Y-m-d', $timestamp);

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Using DATE() function to compare only the date part of the timestamp
    $stmt = $db->prepare(
        'SELECT
            o.id AS order_id,
            COALESCE(c.name, c.shop_name, "Unknown Customer") AS customer_name,
            o.total_price,
            c.phone,
            c.specific_address,
            c.location,
            o.comment,
            COALESCE(o.deliver_status, 0) AS deliver_status
        FROM orders o
        LEFT JOIN customer c ON c.id = o.customer_id
        WHERE DATE(o.order_time) = :order_date
        ORDER BY o.order_time DESC'
    );
    $stmt->execute([':order_date' => $formattedDate]);

    $orders = $stmt->fetchAll();

    $orderList = [];
    $suppliersMap = [];

    $orderIds = array_values(array_unique(array_map(static fn($row) => (int)$row['order_id'], $orders)));
    if (!empty($orderIds)) {
        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
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
            WHERE ol.orders_id IN (' . $placeholders . ')
            ORDER BY ol.id ASC'
        );
        $itemsStmt->execute($orderIds);
        $rows = $itemsStmt->fetchAll();

        foreach ($rows as $row) {
            $supplierId = isset($row['supplier_id']) ? (int)$row['supplier_id'] : 0;
            if ($supplierId > 0 && !isset($suppliersMap[$supplierId])) {
                $suppliersMap[$supplierId] = [
                    'supplierId' => $supplierId,
                    'supplierName' => $row['supplier_name'] ?? 'Unknown Supplier',
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
    }

    $payload = [
        'success' => true,
        'orders' => array_map(static fn($row) => [
            'orderId' => (int)$row['order_id'],
            'customerName' => $row['customer_name'] ?? 'Unknown Customer',
            'totalPrice' => isset($row['total_price']) ? (float)$row['total_price'] : 0.0,
            'phone' => $row['phone'] ?? null,
            'address' => $row['specific_address'] ?? null,
            'location' => $row['location'] ?? null,
            'comment' => $row['comment'] ?? null,
            'deliverStatus' => (int)$row['deliver_status'],
        ], $orders),
        'orderList' => $orderList,
        'suppliers' => array_values($suppliersMap),
    ];

    if (empty($orders)) {
        $payload['message'] = 'No orders found for the requested date.';
    }

    respond(200, $payload);

} catch (PDOException $exception) {
    // Log the detailed error message for debugging
    error_log('Database Error: ' . $exception->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'A database error occurred while retrieving orders.',
    ]);
} catch (Throwable $exception) {
    // Catch any other unexpected errors
    error_log('Server Error: ' . $exception->getMessage());
    respond(500, [
        'success' => false,
        'message' => 'An unexpected server error occurred.',
    ]);
}

?>
