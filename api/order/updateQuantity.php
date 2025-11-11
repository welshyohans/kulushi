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
    $respond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, [
        'success' => false,
        'message' => 'Unable to read request body.'
    ]);
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    $respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.'
    ]);
}

foreach (['orderListId', 'quantity'] as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, [
            'success' => false,
            'message' => "Missing field: {$field}."
        ]);
    }
}

$orderListId = filter_var(
    $data['orderListId'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($orderListId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'orderListId must be a positive integer.'
    ]);
}

$quantity = filter_var(
    $data['quantity'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0]]
);
if ($quantity === false) {
    $respond(422, [
        'success' => false,
        'message' => 'quantity must be a non-negative integer.'
    ]);
}

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, [
            'success' => false,
            'message' => 'Unable to establish database connection.'
        ]);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $db->beginTransaction();

    $selectStmt = $db->prepare(
        'SELECT orders_id, quantity
         FROM ordered_list
         WHERE id = :orderListId
         FOR UPDATE'
    );
    $selectStmt->execute([':orderListId' => $orderListId]);
    $row = $selectStmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        $db->rollBack();
        $respond(404, [
            'success' => false,
            'message' => 'Ordered list item not found.'
        ]);
    }

    $orderId = isset($row['orders_id']) && $row['orders_id'] !== null
        ? (int)$row['orders_id']
        : null;

    $updateStmt = $db->prepare(
        'UPDATE ordered_list
         SET quantity = :quantity
         WHERE id = :orderListId'
    );
    $updateStmt->execute([
        ':quantity' => $quantity,
        ':orderListId' => $orderListId
    ]);

    $affectedRows = $updateStmt->rowCount();

    if ($orderId !== null) {
        $totalStmt = $db->prepare(
            'SELECT COALESCE(SUM(each_price * quantity), 0) AS total
             FROM ordered_list
             WHERE orders_id = :orderId
               AND status != -1'
        );
        $totalStmt->execute([':orderId' => $orderId]);
        $newTotal = (float)$totalStmt->fetchColumn();

        $orderUpdateStmt = $db->prepare(
            'UPDATE orders
             SET total_price = :totalPrice
             WHERE id = :orderId'
        );
        $orderUpdateStmt->execute([
            ':totalPrice' => $newTotal,
            ':orderId' => $orderId
        ]);
    }

    $db->commit();

    $message = $affectedRows > 0
        ? 'Quantity updated successfully.'
        : 'Quantity already matched the requested value.';

    $respond(200, [
        'success' => true,
        'message' => $message
    ]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Database error in updateQuantity: ' . $exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => 'Database error while updating quantity.'
    ]);
} catch (Throwable $throwable) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Unexpected error in updateQuantity: ' . $throwable->getMessage());
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.'
    ]);
}
