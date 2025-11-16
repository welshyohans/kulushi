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

$respond = static function (int $statusCode, array $payload): void {
    http_response_code($statusCode);
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

foreach (['orderId', 'customerId', 'deliverStatus'] as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, [
            'success' => false,
            'message' => "Missing field: {$field}."
        ]);
    }
}

$orderId = filter_var(
    $data['orderId'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($orderId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'orderId must be a positive integer.'
    ]);
}

$customerId = filter_var(
    $data['customerId'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($customerId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'customerId must be a positive integer.'
    ]);
}

$deliverStatus = filter_var(
    $data['deliverStatus'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 127]]
);
if ($deliverStatus === false) {
    $respond(422, [
        'success' => false,
        'message' => 'deliverStatus must be an integer between 0 and 127.'
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

    $orderStmt = $db->prepare(
        'SELECT 
             o.id,
             o.customer_id,
             o.deliver_status,
             c.permitted_credit,
             c.total_credit,
             c.total_unpaid
         FROM orders o
         INNER JOIN customer c ON c.id = o.customer_id
         WHERE o.id = :orderId
           AND o.customer_id = :customerId
         LIMIT 1
         FOR UPDATE'
    );
    $orderStmt->execute([
        ':orderId' => $orderId,
        ':customerId' => $customerId
    ]);
    $orderRow = $orderStmt->fetch();

    if ($orderRow === false) {
        $db->rollBack();
        $respond(404, [
            'success' => false,
            'message' => 'Order not found for supplied customer.'
        ]);
    }

    $currentDeliverStatus = isset($orderRow['deliver_status']) ? (int)$orderRow['deliver_status'] : 0;
    $permittedCredit = isset($orderRow['permitted_credit']) ? (float)$orderRow['permitted_credit'] : 0.0;
    $customerTotalCredit = isset($orderRow['total_credit']) ? (float)$orderRow['total_credit'] : 0.0;
    $availableCredit = max($permittedCredit - $customerTotalCredit, 0.0);

    $messageParts = [];

    if ($currentDeliverStatus === 1 && $deliverStatus === 3) {
        $adjusted = adjustOrderListPrices($db, $orderId, -1);
        $financials = recalcOrderFinancials($db, $orderId, $availableCredit);
        updateOrderFinancials($db, $orderId, $financials, $deliverStatus, null);

        if ($adjusted > 0) {
            $messageParts[] = "Adjusted {$adjusted} item price" . ($adjusted !== 1 ? 's' : '') . ' for pick-up.';
        }
        $messageParts[] = 'Order deliver status updated to pick-up.';
    } elseif ($currentDeliverStatus === 3 && $deliverStatus === 1) {
        $adjusted = adjustOrderListPrices($db, $orderId, 1);
        $financials = recalcOrderFinancials($db, $orderId, $availableCredit);
        updateOrderFinancials($db, $orderId, $financials, $deliverStatus, null);

        if ($adjusted > 0) {
            $messageParts[] = "Adjusted {$adjusted} item price" . ($adjusted !== 1 ? 's' : '') . ' back to ordered value.';
        }
        $messageParts[] = 'Order deliver status updated to ordered.';
    } elseif ($deliverStatus === 6) {
        $financials = recalcOrderFinancials($db, $orderId, $availableCredit);
        $unpaid = [
            'cash' => $financials['cash'],
            'credit' => $financials['credit']
        ];
        updateOrderFinancials($db, $orderId, $financials, $deliverStatus, $unpaid);

        if ($currentDeliverStatus !== 6) {
            $customerUpdate = $db->prepare(
                'UPDATE customer
                 SET total_credit = total_credit + :creditDelta,
                     total_unpaid = total_unpaid + :unpaidDelta
                 WHERE id = :customerId'
            );
            $customerUpdate->execute([
                ':creditDelta' => formatMoney($financials['credit']),
                ':unpaidDelta' => formatMoney($financials['total']),
                ':customerId' => $customerId
            ]);
            $messageParts[] = 'Customer totals updated with delivered amounts.';
        }
        $messageParts[] = 'Order marked as delivered and totals recalculated.';
    } elseif ($deliverStatus === 7) {
        $cancelStmt = $db->prepare(
            'UPDATE ordered_list
             SET status = -1
             WHERE orders_id = :orderId'
        );
        $cancelStmt->execute([':orderId' => $orderId]);

        $financials = recalcOrderFinancials($db, $orderId, $availableCredit);
        $financials['cash'] = 0.0;
        $financials['credit'] = 0.0;
        $financials['total'] = 0.0;
        updateOrderFinancials($db, $orderId, $financials, $deliverStatus, ['cash' => 0.0, 'credit' => 0.0]);

        $messageParts[] = 'Order cancelled and items marked inactive.';
    } else {
        if ($deliverStatus === $currentDeliverStatus) {
            $messageParts[] = 'Order already in requested deliver status; no changes applied.';
        } else {
            $updateStmt = $db->prepare(
                'UPDATE orders
                 SET deliver_status = :deliverStatus
                 WHERE id = :orderId'
            );
            $updateStmt->execute([
                ':deliverStatus' => $deliverStatus,
                ':orderId' => $orderId
            ]);
            $messageParts[] = 'Order deliver status updated.';
        }
    }

    $db->commit();

    $message = $messageParts !== []
        ? implode(' ', $messageParts)
        : 'Deliver status processed successfully.';

    $respond(200, [
        'success' => true,
        'message' => $message
    ]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Database error in updateDeliverStatus: ' . $exception->getMessage());
    $respond(500, [
        'success' => false,
        'message' => 'Database error while updating deliver status.'
    ]);
} catch (Throwable $throwable) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Unexpected error in updateDeliverStatus: ' . $throwable->getMessage());
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.'
    ]);
}

function adjustOrderListPrices(PDO $db, int $orderId, int $direction): int
{
    if ($direction === 0) {
        return 0;
    }

    $stmt = $db->prepare(
        'UPDATE ordered_list ol
         LEFT JOIN goods g ON g.id = ol.goods_id
         SET ol.each_price = GREATEST(
                0,
                ROUND(COALESCE(ol.each_price, 0) + (:direction * COALESCE(g.commission, 0)), 2)
             )
         WHERE ol.orders_id = :orderId
           AND ol.goods_id IS NOT NULL
           AND ol.status != -1'
    );
    $stmt->execute([
        ':direction' => $direction,
        ':orderId' => $orderId
    ]);

    return $stmt->rowCount();
}

function recalcOrderFinancials(PDO $db, int $orderId, float $availableCredit): array
{
    $stmt = $db->prepare(
        'SELECT
            COALESCE(SUM(COALESCE(each_price, 0) * COALESCE(quantity, 0)), 0) AS total_price,
            COALESCE(SUM(CASE WHEN COALESCE(eligible_for_credit, 0) = 1 THEN COALESCE(each_price, 0) * COALESCE(quantity, 0) ELSE 0 END), 0) AS eligible_total
         FROM ordered_list
         WHERE orders_id = :orderId
           AND status != -1'
    );
    $stmt->execute([':orderId' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_price' => 0,
        'eligible_total' => 0
    ];

    $total = isset($row['total_price']) ? (float)$row['total_price'] : 0.0;
    $eligible = isset($row['eligible_total']) ? (float)$row['eligible_total'] : 0.0;

    $total = round($total, 2);
    $eligible = round($eligible, 2);

    $creditLimit = max($availableCredit, 0.0);
    $creditAmount = min($creditLimit, $eligible);
    $creditAmount = min($creditAmount, $total);
    $creditAmount = round($creditAmount, 2);

    $cashAmount = round($total - $creditAmount, 2);
    if ($cashAmount < 0) {
        $cashAmount = 0.0;
    }

    return [
        'total' => $total,
        'credit' => $creditAmount,
        'cash' => $cashAmount
    ];
}

function updateOrderFinancials(PDO $db, int $orderId, array $financials, int $deliverStatus, ?array $unpaidOverride): void
{
    $totalPrice = formatMoney($financials['total'] ?? 0.0);
    $cashAmount = formatMoney($financials['cash'] ?? 0.0);
    $creditAmount = formatMoney($financials['credit'] ?? 0.0);

    if ($unpaidOverride !== null) {
        $unpaidCash = formatMoney($unpaidOverride['cash'] ?? $financials['cash'] ?? 0.0);
        $unpaidCredit = formatMoney($unpaidOverride['credit'] ?? $financials['credit'] ?? 0.0);
        $sql = 'UPDATE orders
                SET total_price = :totalPrice,
                    cash_amount = :cashAmount,
                    credit_amount = :creditAmount,
                    unpaid_cash = :unpaidCash,
                    unpaid_credit = :unpaidCredit,
                    deliver_status = :deliverStatus
                WHERE id = :orderId';
        $params = [
            ':totalPrice' => $totalPrice,
            ':cashAmount' => $cashAmount,
            ':creditAmount' => $creditAmount,
            ':unpaidCash' => $unpaidCash,
            ':unpaidCredit' => $unpaidCredit,
            ':deliverStatus' => $deliverStatus,
            ':orderId' => $orderId
        ];
    } else {
        $sql = 'UPDATE orders
                SET total_price = :totalPrice,
                    cash_amount = :cashAmount,
                    credit_amount = :creditAmount,
                    deliver_status = :deliverStatus
                WHERE id = :orderId';
        $params = [
            ':totalPrice' => $totalPrice,
            ':cashAmount' => $cashAmount,
            ':creditAmount' => $creditAmount,
            ':deliverStatus' => $deliverStatus,
            ':orderId' => $orderId
        ];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

function formatMoney(float $value): string
{
    return number_format($value, 2, '.', '');
}
