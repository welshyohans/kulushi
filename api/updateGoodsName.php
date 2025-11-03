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

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../model/Settings.php';

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $goodsStmt = $db->prepare('SELECT id, name, last_update_code FROM goods WHERE id = :id LIMIT 1');
    $goodsStmt->execute([':id' => $goodsId]);
    $goodsRow = $goodsStmt->fetch();

    if ($goodsRow === false) {
        $respond(404, [
            'success' => false,
            'message' => 'Goods not found.',
            'goodsId' => $goodsId
        ]);
    }

    $settings = new Settings($db);

    $db->beginTransaction();

    $newCode = (int)$settings->nextCode();

    $updateStmt = $db->prepare(
        'UPDATE goods
         SET name = :name,
             last_update_code = :code,
             last_update = NOW()
         WHERE id = :id'
    );
    $updateStmt->execute([
        ':name' => $newName,
        ':code' => $newCode,
        ':id' => $goodsId
    ]);

    $rowsAffected = $updateStmt->rowCount();
    $db->commit();

    $respond(200, [
        'success' => true,
        'message' => $rowsAffected > 0
            ? 'Goods name updated successfully.'
            : 'No changes detected; goods name remains the same.',
        'goodsId' => $goodsId,
        'previousName' => $goodsRow['name'],
        'newName' => $newName,
        'lastUpdateCode' => $newCode
    ]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, [
        'success' => false,
        'message' => 'Database error while updating goods name.',
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