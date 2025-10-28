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

if (!array_key_exists('customerId', $data)) {
    $respond(400, [
        'status' => 'error',
        'message' => 'Missing field: customerId'
    ]);
}

$customerId = filter_var($data['customerId'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);
if ($customerId === false) {
    $respond(422, [
        'status' => 'error',
        'message' => 'customerId must be a positive integer'
    ]);
}

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $customerCheck = $db->prepare('SELECT id FROM customer WHERE id = :id LIMIT 1');
    $customerCheck->execute([':id' => $customerId]);
    if ($customerCheck->fetchColumn() === false) {
        $respond(404, [
            'status' => 'error',
            'message' => 'Customer not found',
            'customerId' => $customerId
        ]);
    }

    $ordersStmt = $db->prepare('SELECT total_price, order_time, deliver_status FROM orders WHERE customer_id = :customerId ORDER BY order_time DESC');
    $ordersStmt->execute([':customerId' => $customerId]);
    $orderRows = $ordersStmt->fetchAll();
    $orders = array_map(function (array $row): array {
        return [
            'totalPrice' => $row['total_price'] !== null ? (float)$row['total_price'] : null,
            'orderTime' => $row['order_time'],
            'deliverStatus' => $row['deliver_status'] !== null ? (int)$row['deliver_status'] : null
        ];
    }, $orderRows);

    $paymentsStmt = $db->prepare('SELECT paid_date, amount, `through`, additional_info, credit_left_after_payment FROM payments WHERE customer_id = :customerId ORDER BY paid_date DESC');
    $paymentsStmt->execute([':customerId' => $customerId]);
    $paymentRows = $paymentsStmt->fetchAll();
    $payments = array_map(function (array $row): array {
        return [
            'paidDate' => $row['paid_date'],
            'amount' => $row['amount'] !== null ? (int)$row['amount'] : null,
            'through' => $row['through'],
            'additional_info' => $row['additional_info'],
            'credit_left_after_payment' => $row['credit_left_after_payment'] !== null ? (int)$row['credit_left_after_payment'] : null
        ];
    }, $paymentRows);

    $respond(200, [
        'orders' => $orders,
        'pyaments' => $payments
    ]);
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

// End of file