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

foreach (['goodsId', 'name'] as $field) {
    if (!array_key_exists($field, $data)) {
        $respond(400, [
            'success' => false,
            'message' => "Missing field: {$field}"
        ]);
    }
}

$goodsId = filter_var($data['goodsId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($goodsId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'goodsId must be a positive integer.'
    ]);
}

$newName = trim((string)$data['name']);
if ($newName === '') {
    $respond(422, [
        'success' => false,
        'message' => 'name cannot be empty.'
    ]);
}
if (mb_strlen($newName) < 2) {
    $respond(422, [
        'success' => false,
        'message' => 'name must be at least 2 characters long.'
    ]);
}

$supplierIdRaw = $data['supplierId'] ?? ($data['supplier_id'] ?? null);
if ($supplierIdRaw === null) {
    $respond(400, [
        'success' => false,
        'message' => 'Missing field: supplierId'
    ]);
}
$supplierId = filter_var($supplierIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($supplierId === false) {
    $respond(422, [
        'success' => false,
        'message' => 'supplierId must be a positive integer.'
    ]);
}

$lastUpdateCodeRaw = $data['lastUpdateCode'] ?? ($data['last_update_code'] ?? 0);
$requestedLastUpdateCode = filter_var($lastUpdateCodeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($requestedLastUpdateCode === false) {
    $respond(422, [
        'success' => false,
        'message' => 'lastUpdateCode must be a non-negative integer.'
    ]);
}

// Handle optional description
$hasDescription = array_key_exists('description', $data);
$newDescription = $hasDescription ? trim((string)$data['description']) : null;

$hasTikTokUrl = array_key_exists('tiktokUrl', $data);
$tiktokUrl = $hasTikTokUrl ? trim((string)$data['tiktokUrl']) : null;
$shouldUpdateTikTokUrl = $hasTikTokUrl && $tiktokUrl !== '';

$hasPriority = array_key_exists('priority', $data);
$priorityValue = null;
$shouldUpdatePriority = false;
if ($hasPriority) {
    $rawPriority = $data['priority'];
    $priorityValue = filter_var($rawPriority, FILTER_VALIDATE_INT);
    if ($priorityValue === false) {
        $respond(422, [
            'success' => false,
            'message' => 'priority must be an integer.'
        ]);
    }
    if ($priorityValue !== 0) {
        $shouldUpdatePriority = true;
    }
}

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../model/Settings.php';
require_once __DIR__ . '/../model/SupplierGoods.php';

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // include description, tiktok_url and priority in selected fields
    $goodsStmt = $db->prepare('SELECT id, name, description, last_update_code, tiktok_url, priority FROM goods WHERE id = :id LIMIT 1');
    $goodsStmt->execute([':id' => $goodsId]);
    $goodsRow = $goodsStmt->fetch();

    if ($goodsRow === false) {
        $respond(404, [
            'success' => false,
            'message' => 'Goods not found.',
            'goodsId' => $goodsId
        ]);
    }

    $previousDescription = array_key_exists('description', $goodsRow) ? $goodsRow['description'] : null;
    $previousTikTokUrl = array_key_exists('tiktok_url', $goodsRow) ? $goodsRow['tiktok_url'] : null;
    $previousPriority = array_key_exists('priority', $goodsRow) ? (int)$goodsRow['priority'] : null;

    $settings = new Settings($db);
    $sgModel = new SupplierGoods($db);

    if (!$sgModel->supplierExists($supplierId)) {
        $respond(404, [
            'success' => false,
            'message' => 'Supplier not found.',
            'supplierId' => $supplierId
        ]);
    }

    $db->beginTransaction();

    $newCode = (int)$settings->nextCode();

    // Build update SQL conditionally to avoid touching description when not provided
    $updateSql = 'UPDATE goods
         SET name = :name,
             last_update_code = :code,
             last_update = NOW()';
    if ($hasDescription) {
        $updateSql .= ",\n             description = :description";
    }
    if ($shouldUpdateTikTokUrl) {
        $updateSql .= ",\n             tiktok_url = :tiktokUrl";
    }
    if ($shouldUpdatePriority) {
        $updateSql .= ",\n             priority = :priority";
    }
    $updateSql .= "\n         WHERE id = :id";

    $updateStmt = $db->prepare($updateSql);

    $params = [
        ':name' => $newName,
        ':code' => $newCode,
        ':id' => $goodsId
    ];
    if ($hasDescription) {
        $params[':description'] = $newDescription;
    }
    if ($shouldUpdateTikTokUrl) {
        $params[':tiktokUrl'] = $tiktokUrl;
    }
    if ($shouldUpdatePriority) {
        $params[':priority'] = $priorityValue;
    }

    $updateStmt->execute($params);

    $rowsAffected = $updateStmt->rowCount();
    $db->commit();

    $updatesPayload = $sgModel->buildUpdatesPayload($supplierId, $requestedLastUpdateCode, $settings);

    $respond(200, array_merge($updatesPayload, [
        'success' => true,
        'message' => $rowsAffected > 0
            ? 'Goods updated successfully.'
            : 'No changes detected; goods remains the same (except last_update_code may have changed).',
        'goodsId' => $goodsId,
        'supplierId' => $supplierId,
        'previousName' => $goodsRow['name'],
        'newName' => $newName,
        'previousDescription' => $previousDescription,
        'newDescription' => $hasDescription ? $newDescription : $previousDescription,
        'previousTikTokUrl' => $previousTikTokUrl,
        'newTikTokUrl' => $shouldUpdateTikTokUrl ? $tiktokUrl : $previousTikTokUrl,
        'previousPriority' => $previousPriority,
        'newPriority' => $shouldUpdatePriority ? $priorityValue : $previousPriority,
        'lastUpdateCode' => $newCode,
        'last_update_code' => $newCode,
        'applied_last_update_code' => $newCode
    ]));
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Database error while updating goods.',
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
