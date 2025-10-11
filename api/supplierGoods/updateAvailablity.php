<?php
header('Content-Type: application/json');

include_once '../../config/Database.php';
include_once '../../model/Settings.php';
include_once '../../model/SupplierGoods.php';

$response = function(int $code, array $data) {
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

// Required fields
foreach (['supplier_id','goods_id','is_available'] as $k) {
    if (!array_key_exists($k, $data)) {
        $response(400, ['success' => false, 'message' => "Missing field: $k"]);
    }
}

$supplierId = (int)$data['supplier_id'];
$goodsId = (int)$data['goods_id'];
$isAvailable = (int)$data['is_available'];

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $settings = new Settings($db);
    $sgModel = new SupplierGoods($db);

    // Existence checks
    if (!$sgModel->supplierExists($supplierId)) {
        $response(404, ['success' => false, 'message' => 'Supplier not found', 'supplier_id' => $supplierId]);
    }
    if (!$sgModel->goodsExists($goodsId)) {
        $response(404, ['success' => false, 'message' => 'Goods not found', 'goods_id' => $goodsId]);
    }
    // Ensure relation exists before update
    $existing = $sgModel->findBySupplierAndGoods($supplierId, $goodsId);
    if (!$existing) {
        $response(404, ['success' => false, 'message' => 'Supplier-goods relation not found', 'supplier_id' => $supplierId, 'goods_id' => $goodsId]);
    }

    $db->beginTransaction();

    // Increment settings.last_update_code and use the new value for last_update_available only
    $code = $settings->nextCode();

    $affected = $sgModel->updateAvailability($supplierId, $goodsId, $isAvailable, (int)$code);

    $db->commit();

    $response(200, [
        'success' => true,
        'message' => 'Availability updated',
        'supplier_id' => $supplierId,
        'goods_id' => $goodsId,
        'affected' => $affected,
        'last_update_available' => (int)$code
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

?>