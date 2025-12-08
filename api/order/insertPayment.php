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

foreach (['customerId', 'amount', 'through', 'additionalInfo'] as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, [
            'success' => false,
            'message' => 'Missing field: ' . $field
        ]);
    }
}

$customerId = filter_var($data['customerId'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);
if ($customerId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'customerId must be a positive integer.'
    ]);
}

$amount = filter_var($data['amount'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);
if ($amount === false) {
    $respond(422, [
        'success' => false,
        'message' => 'amount must be a positive integer.'
    ]);
}

$through = trim((string)$data['through']);
if ($through === '') {
    $respond(422, [
        'success' => false,
        'message' => 'through must be a non-empty string.'
    ]);
}

$additionalInfo = trim((string)$data['additionalInfo']);

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

    /**
     * Returns a structure identical to getAllCustomer.php.
     */
    $fetchCustomers = static function (PDO $db): array {
        $stmt = $db->prepare(
            'SELECT
                c.id,
                c.name,
                c.shop_name,
                c.latitude,
                c.longitude,
                c.address_id,
                c.total_credit,
                c.total_unpaid,
                c.location_description,
                r.name AS registered_by_name
            FROM customer c
            LEFT JOIN customer r ON r.id = c.registered_by
            ORDER BY c.name ASC, c.id ASC'
        );
        $stmt->execute();
        $rawCustomers = $stmt->fetchAll();
        $customers = [];

        foreach ($rawCustomers as $row) {
            $shop = isset($row['shop_name']) ? trim((string)$row['shop_name']) : '';
            if ($shop === '') {
                $shop = null;
            }

            $locationDescription = isset($row['location_description']) ? trim((string)$row['location_description']) : '';
            if ($locationDescription === '') {
                $locationDescription = null;
            }

            $registeredByName = isset($row['registered_by_name']) ? trim((string)$row['registered_by_name']) : '';
            if ($registeredByName === '') {
                $registeredByName = null;
            }

            $customers[] = [
                'customerId' => (int)$row['id'],
                'customerName' => (string)$row['name'],
                'shop' => $shop,
                'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                'addressId' => isset($row['address_id']) ? (int)$row['address_id'] : null,
                'totalCredit' => $row['total_credit'] !== null ? (float)$row['total_credit'] : null,
                'registeredBy' => $registeredByName,
                'location_description' => $locationDescription,
                'totalUnpaid' => $row['total_unpaid'] !== null ? (float)$row['total_unpaid'] : null
            ];
        }

        return $customers;
    };

    $db->beginTransaction();

    $customerStmt = $db->prepare('SELECT id FROM customer WHERE id = :id LIMIT 1 FOR UPDATE');
    $customerStmt->execute([':id' => $customerId]);
    $customerRow = $customerStmt->fetch();
    if ($customerRow === false) {
        $db->rollBack();
        $respond(404, [
            'success' => false,
            'message' => 'Customer not found.',
            'customerId' => $customerId
        ]);
    }

    $remaining = $amount;

    $cashOrdersStmt = $db->prepare(
        'SELECT id, unpaid_cash
        FROM orders
        WHERE customer_id = :customerId
          AND deliver_status = 6
          AND unpaid_cash > 0
        ORDER BY order_time ASC, id ASC
        FOR UPDATE'
    );
    $cashOrdersStmt->execute([':customerId' => $customerId]);
    $cashOrders = $cashOrdersStmt->fetchAll();
    $updateCashStmt = $db->prepare('UPDATE orders SET unpaid_cash = :value WHERE id = :orderId');

    foreach ($cashOrders as $order) {
        if ($remaining <= 0) {
            break;
        }

        $current = (int)$order['unpaid_cash'];
        if ($current <= 0) {
            continue;
        }

        $deduct = min($current, $remaining);
        $newValue = $current - $deduct;

        $updateCashStmt->execute([
            ':value' => $newValue,
            ':orderId' => $order['id']
        ]);

        $remaining -= $deduct;
    }

    if ($remaining > 0) {
        $creditOrdersStmt = $db->prepare(
            'SELECT id, unpaid_credit
            FROM orders
            WHERE customer_id = :customerId
              AND deliver_status = 6
              AND unpaid_credit > 0
            ORDER BY order_time ASC, id ASC
            FOR UPDATE'
        );
        $creditOrdersStmt->execute([':customerId' => $customerId]);
        $creditOrders = $creditOrdersStmt->fetchAll();
        $updateCreditStmt = $db->prepare('UPDATE orders SET unpaid_credit = :value WHERE id = :orderId');

        foreach ($creditOrders as $order) {
            if ($remaining <= 0) {
                break;
            }

            $current = (int)$order['unpaid_credit'];
            if ($current <= 0) {
                continue;
            }

            $deduct = min($current, $remaining);
            $newValue = $current - $deduct;

            $updateCreditStmt->execute([
                ':value' => $newValue,
                ':orderId' => $order['id']
            ]);

            $remaining -= $deduct;
        }
    }

    $totalsStmt = $db->prepare(
        'SELECT
            COALESCE(SUM(unpaid_credit), 0) AS total_credit,
            COALESCE(SUM(unpaid_cash), 0) AS total_cash
        FROM orders
        WHERE customer_id = :customerId
          AND deliver_status = 6'
    );
    $totalsStmt->execute([':customerId' => $customerId]);
    $totals = $totalsStmt->fetch() ?: ['total_credit' => 0, 'total_cash' => 0];
    $totalCreditValue = (int)$totals['total_credit'];
    $totalCashValue = (int)$totals['total_cash'];
    $totalUnpaidValue = $totalCreditValue + $totalCashValue;

    $customerUpdateStmt = $db->prepare('UPDATE customer SET total_credit = :totalCredit, total_unpaid = :totalUnpaid WHERE id = :customerId');
    $customerUpdateStmt->execute([
        ':totalCredit' => $totalCreditValue,
        ':totalUnpaid' => $totalUnpaidValue,
        ':customerId' => $customerId
    ]);

    $paymentInsertStmt = $db->prepare(
        'INSERT INTO payments (customer_id, amount, `through`, additional_info, credit_left_after_payment)
        VALUES (:customerId, :amount, :through, :additionalInfo, :remainingCredit)'
    );
    $paymentInsertStmt->execute([
        ':customerId' => $customerId,
        ':amount' => $amount,
        ':through' => $through,
        ':additionalInfo' => $additionalInfo,
        ':remainingCredit' => $totalUnpaidValue
    ]);

    $db->commit();

    $customers = $fetchCustomers($db);

    $respond(200, [
        'success' => true,
        'message' => null,
        'customers' => $customers
    ]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Database error.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Server error.',
        'error' => $throwable->getMessage()
    ]);
}
