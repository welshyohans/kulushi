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

date_default_timezone_set('Africa/Addis_Ababa');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../lib/CustomerFinancials.php';

$typeToTable = [
    'credit' => 'customer_manual_credit_entries',
    'profit' => 'customer_manual_profit_entries',
    'loss' => 'customer_manual_loss_entries'
];

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, ['success' => false, 'message' => 'Database connection failed.']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $hasManualCredit = CustomerFinancials::columnExists($db, 'customer', 'manual_credit');
    $hasManualProfit = CustomerFinancials::columnExists($db, 'customer', 'manual_profit');
    $hasManualLoss = CustomerFinancials::columnExists($db, 'customer', 'manual_loss');

    if (!$hasManualCredit || !$hasManualProfit || !$hasManualLoss) {
        $respond(400, [
            'success' => false,
            'message' => 'Manual profit/loss/credit columns not found. Please run the migration: sql files/admin_dashboard_migration.sql'
        ]);
    }

    $missingTables = [];
    foreach ($typeToTable as $tableName) {
        if (!CustomerFinancials::tableExists($db, $tableName)) {
            $missingTables[] = $tableName;
        }
    }
    if ($missingTables !== []) {
        $respond(400, [
            'success' => false,
            'message' => 'Manual adjustment ledger tables not found: ' . implode(', ', $missingTables) . '. Run: sql files/customer_adjustments_ledger.sql'
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $customerId = filter_input(INPUT_GET, 'customerId', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($customerId === false || $customerId === null) {
            $respond(422, ['success' => false, 'message' => 'customerId must be a positive integer.']);
        }

        $type = isset($_GET['type']) ? trim((string)$_GET['type']) : 'all';
        $limitRaw = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
        $limit = max(1, min($limitRaw, 200));

        $fetch = static function (PDO $db, string $table, int $customerId, int $limit): array {
            $stmt = $db->prepare(
                "SELECT id, entry_date, amount, reason, created_by, created_at
                 FROM `$table`
                 WHERE customer_id = :customerId
                 ORDER BY entry_date DESC, created_at DESC, id DESC
                 LIMIT :limit"
            );
            $stmt->bindValue(':customerId', $customerId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        };

        if ($type === 'all') {
            $respond(200, [
                'success' => true,
                'customerId' => $customerId,
                'items' => [
                    'credit' => $fetch($db, $typeToTable['credit'], $customerId, $limit),
                    'profit' => $fetch($db, $typeToTable['profit'], $customerId, $limit),
                    'loss' => $fetch($db, $typeToTable['loss'], $customerId, $limit)
                ]
            ]);
        }

        if (!array_key_exists($type, $typeToTable)) {
            $respond(422, ['success' => false, 'message' => 'type must be one of: credit, profit, loss, all.']);
        }

        $respond(200, [
            'success' => true,
            'customerId' => $customerId,
            'type' => $type,
            'items' => $fetch($db, $typeToTable[$type], $customerId, $limit)
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

    $customerId = isset($payload['customerId']) ? (int)$payload['customerId'] : 0;
    if ($customerId <= 0) {
        $respond(422, ['success' => false, 'message' => 'customerId must be a positive integer.']);
    }

    $type = isset($payload['type']) ? trim((string)$payload['type']) : '';
    if (!array_key_exists($type, $typeToTable)) {
        $respond(422, ['success' => false, 'message' => 'type must be one of: credit, profit, loss.']);
    }

    $amount = isset($payload['amount']) ? (float)$payload['amount'] : 0.0;
    if (abs($amount) < 0.000001) {
        $respond(422, ['success' => false, 'message' => 'amount must be non-zero.']);
    }

    $reason = isset($payload['reason']) ? trim((string)$payload['reason']) : '';
    if ($reason === '') {
        $respond(422, ['success' => false, 'message' => 'reason must be provided.']);
    }

    $entryDateRaw = isset($payload['entryDate']) ? trim((string)$payload['entryDate']) : date('Y-m-d');
    $entryDate = DateTime::createFromFormat('Y-m-d', $entryDateRaw);
    if (!$entryDate) {
        $respond(422, ['success' => false, 'message' => 'Invalid entryDate. Use YYYY-MM-DD.']);
    }

    $createdBy = array_key_exists('createdBy', $payload) ? (int)$payload['createdBy'] : null;

    $db->beginTransaction();

    $lock = $db->prepare('SELECT id FROM customer WHERE id = :customerId LIMIT 1 FOR UPDATE');
    $lock->execute([':customerId' => $customerId]);
    if ($lock->fetchColumn() === false) {
        $db->rollBack();
        $respond(404, ['success' => false, 'message' => 'Customer not found.']);
    }

    $table = $typeToTable[$type];
    $insert = $db->prepare(
        "INSERT INTO `$table` (customer_id, entry_date, amount, reason, created_by)
         VALUES (:customerId, :entryDate, :amount, :reason, :createdBy)"
    );
    $insert->execute([
        ':customerId' => $customerId,
        ':entryDate' => $entryDate->format('Y-m-d'),
        ':amount' => CustomerFinancials::formatMoney($amount),
        ':reason' => $reason,
        ':createdBy' => $createdBy
    ]);

    $manualTotals = CustomerFinancials::syncManualTotalsFromLedgers($db, $customerId);
    $customerTotals = CustomerFinancials::recalcCustomerTotals($db, $customerId);

    $db->commit();

    $respond(201, [
        'success' => true,
        'message' => 'Adjustment saved.',
        'customerId' => $customerId,
        'manualTotals' => $manualTotals,
        'customerTotals' => $customerTotals
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
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}

