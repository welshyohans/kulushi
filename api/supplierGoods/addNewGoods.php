<?php
header('Content-Type: application/json');

include_once '../../config/Database.php';
include_once '../../model/Settings.php';
include_once '../../model/Goods.php';
include_once '../../model/SupplierGoods.php';

$response = function (int $code, array $data) {
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

// Required goods fields (matching NOT NULL schema columns)
$requiredGoods = ['category_id', 'brand_id', 'name', 'description', 'priority', 'show_in_home', 'star_value', 'tiktok_url', 'commission'];
$goodsInput = [];
foreach ($requiredGoods as $key) {
    if (!array_key_exists($key, $data)) {
        $response(400, ['success' => false, 'message' => "Missing field: $key"]);
    }
    $goodsInput[$key] = $data[$key];
}

if (!array_key_exists('supplier_id', $data)) {
    $response(400, ['success' => false, 'message' => 'Missing field: supplier_id']);
}
$supplierId = (int)$data['supplier_id'];

// Required supplier_goods attributes for initial linkage
$requiredRelationFields = ['price','discount_start','discount_price','min_order','is_available_for_credit','is_available'];
foreach ($requiredRelationFields as $field) {
    if (!array_key_exists($field, $data)) {
        $response(400, ['success' => false, 'message' => "Missing field: $field"]);
    }
}

// Optional goods field; default to empty string if omitted
$imageUrl = isset($data['image_url']) ? (string)$data['image_url'] : '';

// Optional supplier_goods fields
$sgInput = [];
$sgInput['price'] = (int)$data['price'];
$sgInput['discount_start'] = (int)$data['discount_start'];
$sgInput['discount_price'] = (int)$data['discount_price'];
$sgInput['min_order'] = (int)$data['min_order'];
$sgInput['is_available_for_credit'] = (int)$data['is_available_for_credit'];
$sgInput['is_available'] = (int)$data['is_available'];

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $settings = new Settings($db);
    $goodsModel = new Goods($db);
    $sgModel = new SupplierGoods($db);

    if (!$sgModel->supplierExists($supplierId)) {
        $response(404, ['success' => false, 'message' => 'Supplier not found', 'supplier_id' => $supplierId]);
    }

    // Validate foreign keys for goods table to provide clearer errors
    $stmt = $db->prepare('SELECT 1 FROM category WHERE id = :id');
    $stmt->execute([':id' => (int)$goodsInput['category_id']]);
    if (!$stmt->fetchColumn()) {
        $response(404, ['success' => false, 'message' => 'Category not found', 'category_id' => (int)$goodsInput['category_id']]);
    }
    $stmt = $db->prepare('SELECT 1 FROM brand WHERE id = :id');
    $stmt->execute([':id' => (int)$goodsInput['brand_id']]);
    if (!$stmt->fetchColumn()) {
        $response(404, ['success' => false, 'message' => 'Brand not found', 'brand_id' => (int)$goodsInput['brand_id']]);
    }

    $db->beginTransaction();

    $code = $settings->nextCode();

    $goodsPayload = [
        'category_id' => (int)$goodsInput['category_id'],
        'brand_id' => (int)$goodsInput['brand_id'],
        'name' => (string)$goodsInput['name'],
        'description' => (string)$goodsInput['description'],
        'priority' => (int)$goodsInput['priority'],
        'show_in_home' => (int)$goodsInput['show_in_home'],
        'image_url' => $imageUrl,
        'last_update_code' => (int)$code,
        'star_value' => (string)$goodsInput['star_value'],
        'tiktok_url' => (string)$goodsInput['tiktok_url'],
        'commission' => (int)$goodsInput['commission']
    ];
    $goodsId = $goodsModel->createGoods($goodsPayload);

    $touchCategory = $db->prepare('UPDATE category SET last_update_code = :code WHERE id = :id');
    $touchCategory->execute([':code' => (int)$code, ':id' => (int)$goodsInput['category_id']]);

    $relationResult = $sgModel->upsertRelation([
        'supplier_id' => $supplierId,
        'goods_id' => $goodsId,
        'price' => $sgInput['price'],
        'discount_start' => $sgInput['discount_start'],
        'discount_price' => $sgInput['discount_price'],
        'min_order' => $sgInput['min_order'],
        'is_available_for_credit' => $sgInput['is_available_for_credit'],
        'is_available' => $sgInput['is_available']
    ], (int)$code);

    $db->commit();

    $response(200, [
        'success' => true,
        'message' => 'Goods created and linked to supplier',
        'goods_id' => $goodsId,
        'supplier_id' => $supplierId,
        'relation' => $relationResult,
        'last_update_code' => (int)$code,
        'image_url' => $imageUrl
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
