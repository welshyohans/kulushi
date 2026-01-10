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

    // Helper function to check if a table exists
    $tableExists = function(PDO $db, string $tableName): bool {
        try {
            $stmt = $db->query("SHOW TABLES LIKE '$tableName'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    // Helper function to check if a column exists in a table
    $columnExists = function(PDO $db, string $tableName, string $columnName): bool {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    $warnings = [];

    // Check if base tables exist
    $hasOrdersTable = $tableExists($db, 'orders');
    $hasCustomerTable = $tableExists($db, 'customer');
    $hasOrderedListTable = $tableExists($db, 'ordered_list');

    if (!$hasOrdersTable) {
        $warnings[] = 'orders table not found. Verify base schema/import.';
    }
    if (!$hasCustomerTable) {
        $warnings[] = 'customer table not found. Verify base schema/import.';
    }

    // Check essential columns for overview calculations
    $hasOrderTime = $hasOrdersTable && $columnExists($db, 'orders', 'order_time');
    $hasOrderTotalPrice = $hasOrdersTable && $columnExists($db, 'orders', 'total_price');
    $hasOrderProfit = $hasOrdersTable && $columnExists($db, 'orders', 'profit');
    $hasOrderDeliverStatus = $hasOrdersTable && $columnExists($db, 'orders', 'deliver_status');
    $hasOrderCustomerId = $hasOrdersTable && $columnExists($db, 'orders', 'customer_id');

    $missingOrderColumns = [];
    if ($hasOrdersTable) {
        if (!$hasOrderTime) { $missingOrderColumns[] = 'order_time'; }
        if (!$hasOrderTotalPrice) { $missingOrderColumns[] = 'total_price'; }
        if (!$hasOrderProfit) { $missingOrderColumns[] = 'profit'; }
        if (!$hasOrderDeliverStatus) { $missingOrderColumns[] = 'deliver_status'; }
        if ($missingOrderColumns) {
            $warnings[] = 'orders table missing columns: ' . implode(', ', $missingOrderColumns) . '.';
        }
    }

    $hasCustomerRegisterAt = $hasCustomerTable && $columnExists($db, 'customer', 'register_at');
    $hasCustomerTotalCredit = $hasCustomerTable && $columnExists($db, 'customer', 'total_credit');

    $missingCustomerColumns = [];
    if ($hasCustomerTable) {
        if (!$hasCustomerRegisterAt) { $missingCustomerColumns[] = 'register_at'; }
        if (!$hasCustomerTotalCredit) { $missingCustomerColumns[] = 'total_credit'; }
        if ($missingCustomerColumns) {
            $warnings[] = 'customer table missing columns: ' . implode(', ', $missingCustomerColumns) . '.';
        }
    }

    // Check if migration tables/columns exist
    $hasSupplierPrice = $hasOrderedListTable && $columnExists($db, 'ordered_list', 'supplier_price');
    $hasOrderedListQuantity = $hasOrderedListTable && $columnExists($db, 'ordered_list', 'quantity');
    $hasOrderedListOrdersId = $hasOrderedListTable && $columnExists($db, 'ordered_list', 'orders_id');
    $hasOrderedListStatus = $hasOrderedListTable && $columnExists($db, 'ordered_list', 'status');

    $hasDailyExpenses = $tableExists($db, 'daily_expenses');
    $hasDailyExpensesAmount = $hasDailyExpenses && $columnExists($db, 'daily_expenses', 'amount');
    $hasDailyExpensesDate = $hasDailyExpenses && $columnExists($db, 'daily_expenses', 'expense_date');

    if ($hasDailyExpenses && (!$hasDailyExpensesAmount || !$hasDailyExpensesDate)) {
        $warnings[] = 'daily_expenses table missing required columns.';
        $hasDailyExpenses = false;
    }

    $hasIsLead = $hasCustomerTable && $columnExists($db, 'customer', 'is_lead');
    $hasManualCredit = $hasCustomerTable && $columnExists($db, 'customer', 'manual_credit');

    // Orders revenue and profit
    $ordersRow = ['revenue' => 0, 'profit' => 0];
    if ($hasOrdersTable && $hasOrderTime && $hasOrderTotalPrice && $hasOrderProfit && $hasOrderDeliverStatus) {
        $ordersStmt = $db->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN deliver_status != 7 THEN total_price ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN deliver_status IN (4,5,6) THEN profit ELSE 0 END), 0) AS profit
             FROM orders
             WHERE order_time BETWEEN :start AND :end'
        );
        $ordersStmt->execute([':start' => $start, ':end' => $end]);
        $ordersRow = $ordersStmt->fetch() ?: ['revenue' => 0, 'profit' => 0];
    }

    // Supplier cost - only if supplier_price column exists
    $supplierCost = 0;
    if ($hasSupplierPrice && $hasOrderedListQuantity && $hasOrderedListOrdersId && $hasOrderedListStatus
        && $hasOrdersTable && $hasOrderTime && $hasOrderDeliverStatus) {
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
        $supplierRow = $supplierStmt->fetch();
        $supplierCost = $supplierRow ? (float)$supplierRow['supplier_cost'] : 0;
    }

    // Daily expenses - only if table exists
    $dailyExpenses = 0;
    if ($hasDailyExpenses) {
        $expenseStmt = $db->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS expenses
             FROM daily_expenses
             WHERE expense_date = :date'
        );
        $expenseStmt->execute([':date' => $date->format('Y-m-d')]);
        $expenseRow = $expenseStmt->fetch();
        $dailyExpenses = $expenseRow ? (float)$expenseRow['expenses'] : 0;
    }

    // New customers - handle is_lead column conditionally
    if ($hasCustomerTable && $hasCustomerRegisterAt && $hasIsLead) {
        $newCustomersStmt = $db->prepare(
            'SELECT COUNT(*) AS total
             FROM customer
             WHERE DATE(register_at) = :date
               AND COALESCE(is_lead, 0) = 0'
        );
    } elseif ($hasCustomerTable && $hasCustomerRegisterAt) {
        $newCustomersStmt = $db->prepare(
            'SELECT COUNT(*) AS total
             FROM customer
             WHERE DATE(register_at) = :date'
        );
    } else {
        $newCustomersStmt = null;
    }
    if ($newCustomersStmt) {
        $newCustomersStmt->execute([':date' => $date->format('Y-m-d')]);
        $newCustomersRow = $newCustomersStmt->fetch() ?: ['total' => 0];
    } else {
        $newCustomersRow = ['total' => 0];
    }

    // New customers who ordered
    $newOrdersRow = ['total' => 0];
    if ($hasOrdersTable && $hasCustomerTable && $hasOrderCustomerId && $hasOrderTime && $hasOrderDeliverStatus && $hasCustomerRegisterAt) {
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
    }

    // Total credit - handle manual_credit and is_lead columns conditionally
    if ($hasCustomerTable && $hasCustomerTotalCredit && $hasManualCredit && $hasIsLead) {
        $creditStmt = $db->prepare(
            'SELECT COALESCE(SUM(COALESCE(total_credit, 0) + COALESCE(manual_credit, 0)), 0) AS total_credit
             FROM customer
             WHERE COALESCE(is_lead, 0) = 0'
        );
    } elseif ($hasCustomerTable && $hasCustomerTotalCredit && $hasManualCredit) {
        $creditStmt = $db->prepare(
            'SELECT COALESCE(SUM(COALESCE(total_credit, 0) + COALESCE(manual_credit, 0)), 0) AS total_credit
             FROM customer'
        );
    } elseif ($hasCustomerTable && $hasCustomerTotalCredit && $hasIsLead) {
        $creditStmt = $db->prepare(
            'SELECT COALESCE(SUM(COALESCE(total_credit, 0)), 0) AS total_credit
             FROM customer
             WHERE COALESCE(is_lead, 0) = 0'
        );
    } elseif ($hasCustomerTable && $hasCustomerTotalCredit) {
        $creditStmt = $db->prepare(
            'SELECT COALESCE(SUM(COALESCE(total_credit, 0)), 0) AS total_credit
             FROM customer'
        );
    } else {
        $creditStmt = null;
    }
    if ($creditStmt) {
        $creditStmt->execute();
        $creditRow = $creditStmt->fetch() ?: ['total_credit' => 0];
    } else {
        $creditRow = ['total_credit' => 0];
    }

    // Trend data
    $trendEnd = clone $date;
    $trendStart = (clone $date)->modify('-' . ($days - 1) . ' days');

    $orderTrendRows = [];
    if ($hasOrdersTable && $hasOrderTime && $hasOrderTotalPrice && $hasOrderProfit && $hasOrderDeliverStatus) {
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
    }

    // Expense trend - only if table exists
    $expenseTrendRows = [];
    if ($hasDailyExpenses) {
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
    }

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
            'dailyExpenses' => $dailyExpenses,
            'dailySupplierCost' => $supplierCost,
            'newCustomersRegistered' => (int)$newCustomersRow['total'],
            'newCustomersOrdered' => (int)$newOrdersRow['total'],
            'totalCredit' => (float)$creditRow['total_credit']
        ],
        'trends' => $trend,
        'schema_status' => [
            'has_orders' => $hasOrdersTable,
            'has_customer' => $hasCustomerTable,
            'has_ordered_list' => $hasOrderedListTable,
            'has_order_time' => $hasOrderTime,
            'has_order_total_price' => $hasOrderTotalPrice,
            'has_order_profit' => $hasOrderProfit,
            'has_order_deliver_status' => $hasOrderDeliverStatus,
            'has_order_customer_id' => $hasOrderCustomerId,
            'has_customer_register_at' => $hasCustomerRegisterAt,
            'has_customer_total_credit' => $hasCustomerTotalCredit,
            'has_supplier_price' => $hasSupplierPrice,
            'has_daily_expenses' => $hasDailyExpenses,
            'has_is_lead' => $hasIsLead,
            'has_manual_credit' => $hasManualCredit
        ],
        'warnings' => $warnings
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
