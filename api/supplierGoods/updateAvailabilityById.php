<?php
header('Content-Type: application/json');

include_once '../../config/Database.php';
include_once '../../model/Settings.php';
include_once '../../model/SupplierGoods.php';

$response = function (int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response(405, ['success' => false, 'message' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $response(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

$supplierGoodsId = $data['supplierGoodsId'] ?? $data['supplier_goods_id'] ?? null;
$isAvailable = $data['is_available'] ?? null;

if ($supplierGoodsId === null) {
    $response(400, ['success' => false, 'message' => 'Missing field: supplierGoodsId']);
}
if ($isAvailable === null) {
    $response(400, ['success' => false, 'message' => 'Missing field: is_available']);
}

$supplierGoodsId = (int)$supplierGoodsId;
$isAvailable = (int)$isAvailable;

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $settings = new Settings($db);
    $sgModel = new SupplierGoods($db);

    $existing = $sgModel->findById($supplierGoodsId);
    if (!$existing) {
        $response(404, ['success' => false, 'message' => 'Supplier goods record not found', 'supplier_goods_id' => $supplierGoodsId]);
    }

    $db->beginTransaction();

    $code = $settings->nextCode();
    $affected = $sgModel->updateAvailabilityById($supplierGoodsId, $isAvailable, (int)$code);

    $db->commit();

    $response(200, [
        'success' => true,
        'message' => 'Supplier goods availability updated',
        'supplier_goods_id' => $supplierGoodsId,
        'affected' => $affected,
        'last_update_code' => (int)$code
    ]);
} catch (PDOException $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $response(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $response(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}
