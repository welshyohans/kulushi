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

// Required identifiers
if (!array_key_exists('supplier_id', $data)) {
    $response(400, ['success' => false, 'message' => 'Missing field: supplier_id']);
}
if (!array_key_exists('goods_id', $data)) {
    $response(400, ['success' => false, 'message' => 'Missing field: goods_id']);
}
$supplierId = (int)$data['supplier_id'];
$goodsId = (int)$data['goods_id'];

// Optional fields with defaults
$payload = [];
$payload['price'] = isset($data['price']) ? (int)$data['price'] : 0;
$payload['discount_start'] = isset($data['discount_start']) ? (int)$data['discount_start'] : 0;
$payload['discount_price'] = isset($data['discount_price']) ? (int)$data['discount_price'] : 0;
$payload['min_order'] = isset($data['min_order']) ? (int)$data['min_order'] : 1;
$payload['is_available_for_credit'] = isset($data['is_available_for_credit']) ? (int)$data['is_available_for_credit'] : 0;
$payload['is_available'] = isset($data['is_available']) ? (int)$data['is_available'] : 1;

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

    $db->beginTransaction();

    // Get incremented last_update_code
    $code = $settings->nextCode();

    // Upsert relation
    $res = $sgModel->upsertRelation(array_merge($payload, [
        'supplier_id' => $supplierId,
        'goods_id' => $goodsId
    ]), (int)$code);

    $db->commit();

    $response(200, [
        'success' => true,
        'message' => 'Supplier-goods relation upserted',
        'supplier_id' => $supplierId,
        'goods_id' => $goodsId,
        'result' => $res,
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

?>