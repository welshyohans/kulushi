<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

date_default_timezone_set('Africa/Addis_Ababa');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

$dateRaw = isset($_GET['date']) ? trim((string)$_GET['date']) : date('Y-m-d');
$daysRaw = isset($_GET['days']) ? (int)$_GET['days'] : 7;
$days = max(1, min($daysRaw, 30));

$date = DateTime::createFromFormat('Y-m-d', $dateRaw);
if (!$date) {
    $respond(422, [
        'success' => false,
        'message' => 'Invalid date format. Use YYYY-MM-DD.'
    ]);
}

$start = $date->format('Y-m-d 00:00:00');
$end = $date->format('Y-m-d 23:59:59');

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, ['success' => false, 'message' => 'Database connection failed.']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $ordersStmt = $db->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN deliver_status != 7 THEN total_price ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN deliver_status IN (4,5,6) THEN profit ELSE 0 END), 0) AS profit
         FROM orders
         WHERE order_time BETWEEN :start AND :end'
    );
    $ordersStmt->execute([':start' => $start, ':end' => $end]);
    $ordersRow = $ordersStmt->fetch() ?: ['revenue' => 0, 'profit' => 0];

    $supplierStmt = $db->prepare(
        'SELECT
            COALESCE(SUM(COALESCE(ol.supplier_price, 0) * COALESCE(ol.quantity, 0)), 0) AS supplier_cost
         FROM ordered_list ol
         INNER JOIN orders o ON o.id = ol.orders_id
         WHERE o.order_time BETWEEN :start AND :end
           AND o.deliver_status IN (4,5,6)
           AND ol.status != -1'
    );
    $supplierStmt->execute([':start' => $start, ':end' => $end]);
    $supplierRow = $supplierStmt->fetch() ?: ['supplier_cost' => 0];

    $expenseStmt = $db->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS expenses
         FROM daily_expenses
         WHERE expense_date = :date'
    );
    $expenseStmt->execute([':date' => $date->format('Y-m-d')]);
    $expenseRow = $expenseStmt->fetch() ?: ['expenses' => 0];

    $newCustomersStmt = $db->prepare(
        'SELECT COUNT(*) AS total
         FROM customer
         WHERE DATE(register_at) = :date
           AND COALESCE(is_lead, 0) = 0'
    );
    $newCustomersStmt->execute([':date' => $date->format('Y-m-d')]);
    $newCustomersRow = $newCustomersStmt->fetch() ?: ['total' => 0];

    $newOrdersStmt = $db->prepare(
        'SELECT COUNT(DISTINCT o.customer_id) AS total
         FROM orders o
         INNER JOIN customer c ON c.id = o.customer_id
         WHERE DATE(o.order_time) = :date
           AND DATE(c.register_at) = :date
           AND o.deliver_status != 7'
    );
    $newOrdersStmt->execute([':date' => $date->format('Y-m-d')]);
    $newOrdersRow = $newOrdersStmt->fetch() ?: ['total' => 0];

    $creditStmt = $db->prepare(
        'SELECT COALESCE(SUM(COALESCE(total_credit, 0) + COALESCE(manual_credit, 0)), 0) AS total_credit
         FROM customer
         WHERE COALESCE(is_lead, 0) = 0'
    );
    $creditStmt->execute();
    $creditRow = $creditStmt->fetch() ?: ['total_credit' => 0];

    $trendEnd = clone $date;
    $trendStart = (clone $date)->modify('-' . ($days - 1) . ' days');

    $trendOrdersStmt = $db->prepare(
        'SELECT
            DATE(order_time) AS day,
            COALESCE(SUM(CASE WHEN deliver_status != 7 THEN total_price ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN deliver_status IN (4,5,6) THEN profit ELSE 0 END), 0) AS profit
         FROM orders
         WHERE order_time BETWEEN :start AND :end
         GROUP BY DATE(order_time)'
    );
    $trendOrdersStmt->execute([
        ':start' => $trendStart->format('Y-m-d 00:00:00'),
        ':end' => $trendEnd->format('Y-m-d 23:59:59')
    ]);
    $orderTrendRows = $trendOrdersStmt->fetchAll();

    $trendExpensesStmt = $db->prepare(
        'SELECT expense_date AS day, COALESCE(SUM(amount), 0) AS expenses
         FROM daily_expenses
         WHERE expense_date BETWEEN :start AND :end
         GROUP BY expense_date'
    );
    $trendExpensesStmt->execute([
        ':start' => $trendStart->format('Y-m-d'),
        ':end' => $trendEnd->format('Y-m-d')
    ]);
    $expenseTrendRows = $trendExpensesStmt->fetchAll();

    $trendMap = [];
    foreach ($orderTrendRows as $row) {
        $trendMap[$row['day']] = [
            'revenue' => (float)$row['revenue'],
            'profit' => (float)$row['profit'],
            'expenses' => 0.0
        ];
    }
    foreach ($expenseTrendRows as $row) {
        $day = $row['day'];
        if (!isset($trendMap[$day])) {
            $trendMap[$day] = ['revenue' => 0.0, 'profit' => 0.0, 'expenses' => 0.0];
        }
        $trendMap[$day]['expenses'] = (float)$row['expenses'];
    }

    $trend = [];
    $cursor = clone $trendStart;
    while ($cursor <= $trendEnd) {
        $key = $cursor->format('Y-m-d');
        $entry = $trendMap[$key] ?? ['revenue' => 0.0, 'profit' => 0.0, 'expenses' => 0.0];
        $trend[] = [
            'date' => $key,
            'revenue' => $entry['revenue'],
            'profit' => $entry['profit'],
            'expenses' => $entry['expenses']
        ];
        $cursor->modify('+1 day');
    }

    $respond(200, [
        'success' => true,
        'date' => $date->format('Y-m-d'),
        'timezone' => 'Africa/Addis_Ababa',
        'metrics' => [
            'dailyProfit' => (float)$ordersRow['profit'],
            'dailyRevenue' => (float)$ordersRow['revenue'],
            'dailyExpenses' => (float)$expenseRow['expenses'],
            'dailySupplierCost' => (float)$supplierRow['supplier_cost'],
            'newCustomersRegistered' => (int)$newCustomersRow['total'],
            'newCustomersOrdered' => (int)$newOrdersRow['total'],
            'totalCredit' => (float)$creditRow['total_credit']
        ],
        'trends' => $trend
    ]);
} catch (PDOException $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Database error.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}
