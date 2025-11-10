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
        'orders' => []
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, [
        'success' => false,
        'message' => 'Unable to read request body.',
        'orders' => []
    ]);
}

$data = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    $respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.',
        'orders' => []
    ]);
}

if (!array_key_exists('date', $data)) {
    $respond(400, [
        'success' => false,
        'message' => 'Missing field: date.',
        'orders' => []
    ]);
}

$dateRaw = trim($data['date'] ?? '');
$formattedDate = null;
$acceptedFormats = ['Y-m-d', 'd-m-Y', 'm/d/Y', 'd/m/Y', 'Y/m/d'];

foreach ($acceptedFormats as $format) {
    $dateTime = DateTime::createFromFormat($format, $dateRaw);
    if ($dateTime !== false && $dateTime->format($format) === $dateRaw) {
        $formattedDate = $dateTime->format('Y-m-d');
        break;
    }
}

if ($formattedDate === null) {
    $respond(400, [
        'success' => false,
        'message' => 'Invalid date format. Accepted formats: YYYY-MM-DD, DD-MM-YYYY, MM/DD/YYYY, DD/MM/YYYY, YYYY/MM/DD.',
        'orders' => []
    ]);
}

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

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

    $orders = [];
    while ($row = $stmt->fetch()) {
        $orders[] = [
            'orderId' => (int)$row['order_id'],
            'customerName' => $row['customer_name'] ?? 'Unknown Customer',
            'totalPrice' => isset($row['total_price']) ? (float)$row['total_price'] : 0.0,
            'phone' => $row['phone'] ?? null,
            'address' => $row['specific_address'] ?? null,
            'location' => $row['location'] ?? null,
            'comment' => $row['comment'] ?? null,
            'deliverStatus' => (int)$row['deliver_status'],
        ];
    }

    $payload = [
        'success' => true,
        'orders' => $orders,
    ];

    if (empty($orders)) {
        $payload['message'] = 'No orders found for the requested date.';
    }

    $respond(200, $payload);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => 'Database error while retrieving admin orders.',
        'orders' => []
    ]);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error while retrieving admin orders.',
        'orders' => []
    ]);
}
