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
require_once __DIR__ . '/../lib/CustomerFinancials.php';

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

    // If money remains after settling orders, pay down manual credit (if enabled).
    if ($remaining > 0 && CustomerFinancials::columnExists($db, 'customer', 'manual_credit')) {
        $manualCreditRowStmt = $db->prepare('SELECT COALESCE(manual_credit, 0) AS manual_credit FROM customer WHERE id = :customerId LIMIT 1 FOR UPDATE');
        $manualCreditRowStmt->execute([':customerId' => $customerId]);
        $manualCreditRow = $manualCreditRowStmt->fetch() ?: ['manual_credit' => 0];
        $manualCredit = (float)$manualCreditRow['manual_credit'];

        if ($manualCredit > 0) {
            $deduct = min($manualCredit, (float)$remaining);

            if (CustomerFinancials::tableExists($db, 'customer_manual_credit_entries')) {
                $reason = 'Payment: ' . $through . ($additionalInfo !== '' ? ' - ' . $additionalInfo : '');
                $insertManual = $db->prepare(
                    'INSERT INTO customer_manual_credit_entries (customer_id, entry_date, amount, reason)
                     VALUES (:customerId, :entryDate, :amount, :reason)'
                );
                $insertManual->execute([
                    ':customerId' => $customerId,
                    ':entryDate' => date('Y-m-d'),
                    ':amount' => CustomerFinancials::formatMoney(-$deduct),
                    ':reason' => $reason
                ]);

                CustomerFinancials::syncManualTotalsFromLedgers($db, $customerId);
            } else {
                $updateManual = $db->prepare('UPDATE customer SET manual_credit = :value WHERE id = :customerId');
                $updateManual->execute([
                    ':value' => CustomerFinancials::formatMoney($manualCredit - $deduct),
                    ':customerId' => $customerId
                ]);
            }

            $remaining -= $deduct;
        }
    }

    $customerTotals = CustomerFinancials::recalcCustomerTotals($db, $customerId);
    $totalUnpaidValue = (float)$customerTotals['total_unpaid'];

    $paymentInsertStmt = $db->prepare(
        'INSERT INTO payments (customer_id, amount, `through`, additional_info, credit_left_after_payment)
        VALUES (:customerId, :amount, :through, :additionalInfo, :remainingCredit)'
    );
    $paymentInsertStmt->execute([
        ':customerId' => $customerId,
        ':amount' => $amount,
        ':through' => $through,
        ':additionalInfo' => $additionalInfo,
        ':remainingCredit' => (int)round($totalUnpaidValue)
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
