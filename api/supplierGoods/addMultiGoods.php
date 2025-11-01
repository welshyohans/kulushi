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

if (!array_key_exists('supplier_id', $data)) {
    $response(400, ['success' => false, 'message' => 'Missing field: supplier_id']);
}
$supplierId = (int)$data['supplier_id'];

if (!array_key_exists('goods', $data)) {
    $response(400, ['success' => false, 'message' => 'Missing field: goods']);
}
$goodsList = $data['goods'];
if (!is_array($goodsList) || count($goodsList) === 0) {
    $response(400, ['success' => false, 'message' => 'Goods list must be a non-empty array']);
}

$requiredGoods = ['category_id', 'brand_id', 'name', 'description', 'priority', 'show_in_home', 'star_value', 'tiktok_url', 'commission'];
$requiredRelationFields = ['price', 'discount_start', 'discount_price', 'min_order', 'is_available_for_credit', 'is_available'];

foreach ($goodsList as $index => $item) {
    if (!is_array($item)) {
        $response(400, ['success' => false, 'message' => "Invalid goods entry at index $index"]);
    }
    foreach ($requiredGoods as $field) {
        if (!array_key_exists($field, $item)) {
            $response(400, ['success' => false, 'message' => "Missing field in goods[$index]: $field"]);
        }
    }
    foreach ($requiredRelationFields as $field) {
        if (!array_key_exists($field, $item)) {
            $response(400, ['success' => false, 'message' => "Missing field in goods[$index]: $field"]);
        }
    }
}

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

    $categoryLookup = $db->prepare('SELECT 1 FROM category WHERE id = :id');
    $brandLookup = $db->prepare('SELECT 1 FROM brand WHERE id = :id');

    foreach ($goodsList as $index => $item) {
        $categoryLookup->execute([':id' => (int)$item['category_id']]);
        if (!$categoryLookup->fetchColumn()) {
            $categoryLookup->closeCursor();
            $response(404, [
                'success' => false,
                'message' => 'Category not found',
                'category_id' => (int)$item['category_id'],
                'goods_index' => $index
            ]);
        }
        $categoryLookup->closeCursor();

        $brandLookup->execute([':id' => (int)$item['brand_id']]);
        if (!$brandLookup->fetchColumn()) {
            $brandLookup->closeCursor();
            $response(404, [
                'success' => false,
                'message' => 'Brand not found',
                'brand_id' => (int)$item['brand_id'],
                'goods_index' => $index
            ]);
        }
        $brandLookup->closeCursor();
    }

    $db->beginTransaction();

    $touchCategory = $db->prepare('UPDATE category SET last_update_code = :code WHERE id = :id');

    $results = [];

    foreach ($goodsList as $index => $item) {
        $code = $settings->nextCode();
        $imageUrl = isset($item['image_url']) ? (string)$item['image_url'] : '';

        $goodsPayload = [
            'category_id' => (int)$item['category_id'],
            'brand_id' => (int)$item['brand_id'],
            'name' => (string)$item['name'],
            'description' => (string)$item['description'],
            'priority' => (int)$item['priority'],
            'show_in_home' => (int)$item['show_in_home'],
            'image_url' => $imageUrl,
            'last_update_code' => (int)$code,
            'star_value' => (string)$item['star_value'],
            'tiktok_url' => (string)$item['tiktok_url'],
            'commission' => (int)$item['commission']
        ];
        $goodsId = $goodsModel->createGoods($goodsPayload);

        $touchCategory->execute([':code' => (int)$code, ':id' => (int)$item['category_id']]);

        $relationResult = $sgModel->upsertRelation([
            'supplier_id' => $supplierId,
            'goods_id' => $goodsId,
            'price' => (int)$item['price'],
            'discount_start' => (int)$item['discount_start'],
            'discount_price' => (int)$item['discount_price'],
            'min_order' => (int)$item['min_order'],
            'is_available_for_credit' => (int)$item['is_available_for_credit'],
            'is_available' => (int)$item['is_available']
        ], (int)$code);

        $results[] = [
            'index' => $index,
            'goods_id' => $goodsId,
            'name' => (string)$item['name'],
            'relation' => $relationResult,
            'last_update_code' => (int)$code,
            'image_url' => $imageUrl
        ];
    }

    $db->commit();

    $response(200, [
        'success' => true,
        'message' => 'Goods batch created and linked to supplier',
        'supplier_id' => $supplierId,
        'processed_count' => count($goodsList),
        'items' => $results
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