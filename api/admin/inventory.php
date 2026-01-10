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

    // Check if inventory_entries table exists
    $tableExists = function(PDO $db, string $tableName): bool {
        try {
            $stmt = $db->query("SHOW TABLES LIKE '$tableName'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    $hasInventoryEntries = $tableExists($db, 'inventory_entries');

    if (!$hasInventoryEntries) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $respond(200, [
                'success' => true,
                'count' => 0,
                'items' => [],
                'message' => 'inventory_entries table not found. Please run the migration: sql files/admin_dashboard_migration.sql'
            ]);
        } else {
            $respond(400, [
                'success' => false,
                'message' => 'inventory_entries table not found. Please run the migration: sql files/admin_dashboard_migration.sql'
            ]);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare(
            'SELECT ie.id, ie.goods_id, g.name AS goods_name, ie.quantity, ie.unit_cost, ie.entry_type, ie.note, ie.created_at
             FROM inventory_entries ie
             LEFT JOIN goods g ON g.id = ie.goods_id
             ORDER BY ie.created_at DESC
             LIMIT 200'
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

    $goodsId = isset($payload['goodsId']) ? (int)$payload['goodsId'] : 0;
    $quantity = isset($payload['quantity']) ? (int)$payload['quantity'] : 0;
    $unitCost = isset($payload['unitCost']) ? (float)$payload['unitCost'] : 0.0;
    $entryType = isset($payload['entryType']) ? trim((string)$payload['entryType']) : '';
    $note = isset($payload['note']) ? trim((string)$payload['note']) : null;
    $createdBy = isset($payload['createdBy']) ? (int)$payload['createdBy'] : null;

    $allowedTypes = ['bulk_purchase', 'return', 'manual', 'adjustment'];
    if ($goodsId <= 0 || $quantity === 0) {
        $respond(422, ['success' => false, 'message' => 'goodsId and quantity are required.']);
    }
    if ($entryType === '' || !in_array($entryType, $allowedTypes, true)) {
        $respond(422, ['success' => false, 'message' => 'entryType must be one of: ' . implode(', ', $allowedTypes)]);
    }

    $stmt = $db->prepare(
        'INSERT INTO inventory_entries (goods_id, quantity, unit_cost, entry_type, note, created_by)
         VALUES (:goods_id, :quantity, :unit_cost, :entry_type, :note, :created_by)'
    );
    $stmt->execute([
        ':goods_id' => $goodsId,
        ':quantity' => $quantity,
        ':unit_cost' => number_format($unitCost, 2, '.', ''),
        ':entry_type' => $entryType,
        ':note' => $note,
        ':created_by' => $createdBy
    ]);

    $respond(201, ['success' => true, 'message' => 'Inventory entry saved.']);
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
