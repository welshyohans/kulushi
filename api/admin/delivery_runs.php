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
        $stmt = $db->prepare(
            'SELECT dr.id, dr.address_id, dr.run_date, dr.eta_window, dr.note, dr.status, dr.created_at,
                    a.city, a.sub_city
             FROM delivery_runs dr
             LEFT JOIN address a ON a.id = dr.address_id
             ORDER BY dr.run_date DESC, dr.created_at DESC
             LIMIT 100'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $respond(200, ['success' => true, 'count' => count($rows), 'items' => $rows]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '', true);
    if (!is_array($payload)) {
        $respond(400, ['success' => false, 'message' => 'Invalid JSON payload.']);
    }

    $addressId = isset($payload['addressId']) ? (int)$payload['addressId'] : 0;
    $runDateRaw = isset($payload['runDate']) ? trim((string)$payload['runDate']) : '';
    $etaWindow = isset($payload['etaWindow']) ? trim((string)$payload['etaWindow']) : '';
    $note = isset($payload['note']) ? trim((string)$payload['note']) : null;
    $createdBy = isset($payload['createdBy']) ? (int)$payload['createdBy'] : null;

    if ($addressId <= 0) {
        $respond(422, ['success' => false, 'message' => 'addressId must be provided.']);
    }

    $runDate = DateTime::createFromFormat('Y-m-d', $runDateRaw);
    if (!$runDate) {
        $respond(422, ['success' => false, 'message' => 'runDate must be YYYY-MM-DD.']);
    }

    $stmt = $db->prepare(
        'INSERT INTO delivery_runs (address_id, run_date, eta_window, note, created_by)
         VALUES (:address_id, :run_date, :eta_window, :note, :created_by)'
    );
    $stmt->execute([
        ':address_id' => $addressId,
        ':run_date' => $runDate->format('Y-m-d'),
        ':eta_window' => $etaWindow === '' ? null : $etaWindow,
        ':note' => $note,
        ':created_by' => $createdBy
    ]);

    $respond(201, ['success' => true, 'message' => 'Delivery run saved.']);
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
