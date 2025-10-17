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
$price = $data['price'] ?? null;
$minOrder = $data['min_order'] ?? null;
$discountStart = $data['discount_start'] ?? null;
$discountPrice = $data['discount_price'] ?? null;
$isAvailableForCredit = $data['is_available_for_credit'] ?? null;

foreach ([
    'supplierGoodsId' => $supplierGoodsId,
    'price' => $price,
    'min_order' => $minOrder,
    'discount_start' => $discountStart,
    'discount_price' => $discountPrice,
    'is_available_for_credit' => $isAvailableForCredit
] as $field => $value) {
    if ($value === null) {
        $response(400, ['success' => false, 'message' => "Missing field: {$field}"]);
    }
}

$updatePayload = [
    'price' => (int)$price,
    'min_order' => (int)$minOrder,
    'discount_start' => (int)$discountStart,
    'discount_price' => (int)$discountPrice,
    'is_available_for_credit' => (int)$isAvailableForCredit
];

if (array_key_exists('is_available', $data)) {
    $updatePayload['is_available'] = (int)$data['is_available'];
}

$supplierGoodsId = (int)$supplierGoodsId;

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
    $affected = $sgModel->updateById($supplierGoodsId, $updatePayload, (int)$code);

    $db->commit();

    $response(200, [
        'success' => true,
        'message' => 'Supplier goods price details updated',
        'supplier_goods_id' => $supplierGoodsId,
        'affected' => $affected,
        'last_update_code' => (int)$code
    ]);
} catch (PDOException $e) {
    if (isset($db) && $db instanceof PDO) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    $response(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $response(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}
