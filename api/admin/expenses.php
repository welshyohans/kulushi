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

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, ['success' => false, 'message' => 'Database connection failed.']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $dateRaw = isset($_GET['date']) ? trim((string)$_GET['date']) : date('Y-m-d');
        $date = DateTime::createFromFormat('Y-m-d', $dateRaw);
        if (!$date) {
            $respond(422, ['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
        }

        $stmt = $db->prepare(
            'SELECT id, expense_date, amount, reason, created_by, created_at
             FROM daily_expenses
             WHERE expense_date = :date
             ORDER BY created_at DESC'
        );
        $stmt->execute([':date' => $date->format('Y-m-d')]);
        $rows = $stmt->fetchAll();

        $totalStmt = $db->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total
             FROM daily_expenses
             WHERE expense_date = :date'
        );
        $totalStmt->execute([':date' => $date->format('Y-m-d')]);
        $totalRow = $totalStmt->fetch() ?: ['total' => 0];

        $respond(200, [
            'success' => true,
            'date' => $date->format('Y-m-d'),
            'total' => (float)$totalRow['total'],
            'items' => $rows
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

    $amount = isset($payload['amount']) ? (float)$payload['amount'] : 0.0;
    $reason = isset($payload['reason']) ? trim((string)$payload['reason']) : '';
    $dateRaw = isset($payload['expenseDate']) ? trim((string)$payload['expenseDate']) : date('Y-m-d');
    $createdBy = isset($payload['createdBy']) ? (int)$payload['createdBy'] : null;

    if ($amount <= 0) {
        $respond(422, ['success' => false, 'message' => 'amount must be greater than 0.']);
    }
    if ($reason === '') {
        $respond(422, ['success' => false, 'message' => 'reason must be provided.']);
    }
    $date = DateTime::createFromFormat('Y-m-d', $dateRaw);
    if (!$date) {
        $respond(422, ['success' => false, 'message' => 'Invalid expenseDate. Use YYYY-MM-DD.']);
    }

    $stmt = $db->prepare(
        'INSERT INTO daily_expenses (expense_date, amount, reason, created_by)
         VALUES (:expense_date, :amount, :reason, :created_by)'
    );
    $stmt->execute([
        ':expense_date' => $date->format('Y-m-d'),
        ':amount' => number_format($amount, 2, '.', ''),
        ':reason' => $reason,
        ':created_by' => $createdBy
    ]);

    $respond(201, [
        'success' => true,
        'message' => 'Expense saved.'
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
