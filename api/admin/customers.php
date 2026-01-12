<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

require_once __DIR__ . '/../../config/Database.php';

$allowedSegments = ['new', 'active', 'loyal', 'vip', 'at_risk', 'churned', 'potential'];

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, ['success' => false, 'message' => 'Database connection failed.']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // Helper function to check if a column exists in a table
    $columnExists = function(PDO $db, string $tableName, string $columnName): bool {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    // Check which migration columns exist
    $hasIsLead = $columnExists($db, 'customer', 'is_lead');
    $hasSegment = $columnExists($db, 'customer', 'segment');
    $hasManualProfit = $columnExists($db, 'customer', 'manual_profit');
    $hasManualLoss = $columnExists($db, 'customer', 'manual_loss');
    $hasManualCredit = $columnExists($db, 'customer', 'manual_credit');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $segment = isset($_GET['segment']) ? trim((string)$_GET['segment']) : '';
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
        $limit = max(1, min($limit, 500));

        $where = [];
        $params = [];

        // Only filter by is_lead if the column exists
        if ($hasIsLead) {
            $where[] = 'COALESCE(c.is_lead, 0) = 0';
        }

        // Only filter by segment if the column exists
        if ($segment !== '' && $hasSegment) {
            $where[] = 'c.segment = :segment';
            $params[':segment'] = $segment;
        }

        if ($search !== '') {
            $where[] = '(c.name LIKE :search OR c.phone LIKE :search OR c.shop_name LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        // Build dynamic SELECT columns based on what exists
        $selectColumns = [
            'c.id',
            'c.name',
            'c.phone',
            'c.shop_name',
            'c.total_credit',
            'c.total_unpaid',
            'c.register_at'
        ];

        if ($hasSegment) {
            $selectColumns[] = 'c.segment';
        } else {
            $selectColumns[] = "'new' AS segment";
        }

        if ($hasManualProfit) {
            $selectColumns[] = 'c.manual_profit';
        } else {
            $selectColumns[] = '0 AS manual_profit';
        }

        if ($hasManualLoss) {
            $selectColumns[] = 'c.manual_loss';
        } else {
            $selectColumns[] = '0 AS manual_loss';
        }

        if ($hasManualCredit) {
            $selectColumns[] = 'c.manual_credit';
        } else {
            $selectColumns[] = '0 AS manual_credit';
        }

        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = '
            SELECT
                ' . implode(', ', $selectColumns) . ',
                COALESCE(SUM(CASE WHEN o.deliver_status IN (4,5,6) THEN o.profit ELSE 0 END), 0) AS commission_profit,
                COALESCE(SUM(CASE WHEN o.deliver_status != 7 THEN o.total_price ELSE 0 END), 0) AS revenue,
                COUNT(o.id) AS orders_count
            FROM customer c
            LEFT JOIN orders o ON o.customer_id = c.id
            ' . $whereClause . '
            GROUP BY c.id
            ORDER BY c.register_at DESC
            LIMIT :limit';

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        $respond(200, [
            'success' => true,
            'count' => count($rows),
            'items' => $rows,
            'schema_status' => [
                'has_segment' => $hasSegment,
                'has_manual_profit' => $hasManualProfit,
                'has_manual_loss' => $hasManualLoss,
                'has_manual_credit' => $hasManualCredit,
                'has_is_lead' => $hasIsLead
            ]
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '', true);
    if (!is_array($payload)) {
        $respond(400, ['success' => false, 'message' => 'Invalid JSON payload.']);
    }

    $action = isset($payload['action']) ? trim((string)$payload['action']) : '';
    $customerId = isset($payload['customerId']) ? (int)$payload['customerId'] : 0;
    if ($customerId <= 0) {
        $respond(422, ['success' => false, 'message' => 'customerId must be a positive integer.']);
    }

    if ($action === 'update_segment') {
        if (!$hasSegment) {
            $respond(400, [
                'success' => false,
                'message' => 'segment column not found. Please run the migration: sql files/admin_dashboard_migration.sql'
            ]);
        }

        $segment = isset($payload['segment']) ? trim((string)$payload['segment']) : '';
        if ($segment === '' || !in_array($segment, $allowedSegments, true)) {
            $respond(422, [
                'success' => false,
                'message' => 'segment must be one of: ' . implode(', ', $allowedSegments)
            ]);
        }

        $stmt = $db->prepare('UPDATE customer SET segment = :segment WHERE id = :customerId');
        $stmt->execute([
            ':segment' => $segment,
            ':customerId' => $customerId
        ]);

        $respond(200, ['success' => true, 'message' => 'Segment updated.']);
    }

    if ($action === 'update_financials') {
        if (!$hasManualProfit || !$hasManualLoss || !$hasManualCredit) {
            $respond(400, [
                'success' => false,
                'message' => 'Manual profit/loss/credit columns not found. Please run the migration: sql files/admin_dashboard_migration.sql'
            ]);
        }

        $manualProfit = isset($payload['manualProfit']) ? (float)$payload['manualProfit'] : 0.0;
        $manualLoss = isset($payload['manualLoss']) ? (float)$payload['manualLoss'] : 0.0;
        $manualCredit = isset($payload['manualCredit']) ? (float)$payload['manualCredit'] : 0.0;

        $stmt = $db->prepare(
            'UPDATE customer
             SET manual_profit = :manual_profit,
                 manual_loss = :manual_loss,
                 manual_credit = :manual_credit
             WHERE id = :customerId'
        );
        $stmt->execute([
            ':manual_profit' => number_format($manualProfit, 2, '.', ''),
            ':manual_loss' => number_format($manualLoss, 2, '.', ''),
            ':manual_credit' => number_format($manualCredit, 2, '.', ''),
            ':customerId' => $customerId
        ]);

        $respond(200, ['success' => true, 'message' => 'Customer adjustments saved.']);
    }

    if ($action === 'update_credit_total') {
        $totalCredit = isset($payload['totalCredit']) ? (float)$payload['totalCredit'] : 0.0;
        $stmt = $db->prepare('UPDATE customer SET total_credit = :total_credit WHERE id = :customerId');
        $stmt->execute([
            ':total_credit' => number_format($totalCredit, 2, '.', ''),
            ':customerId' => $customerId
        ]);

        $respond(200, ['success' => true, 'message' => 'Total credit updated.']);
    }

    $respond(422, ['success' => false, 'message' => 'Unknown action.']);
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
