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
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $response(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

$simpleInput = $payload['simple_goods'] ?? ($payload['simpleGoods'] ?? []);
$newInput = $payload['new_goods'] ?? ($payload['newGoods'] ?? []);

if ($simpleInput === null) {
    $simpleInput = [];
}
if ($newInput === null) {
    $newInput = [];
}

if (!is_array($simpleInput)) {
    $response(400, ['success' => false, 'message' => 'Field simple_goods must be an array']);
}
if (!is_array($newInput)) {
    $response(400, ['success' => false, 'message' => 'Field new_goods must be an array']);
}

$simpleItems = array_values($simpleInput);
$newItems = array_values($newInput);

if (count($simpleItems) === 0 && count($newItems) === 0) {
    $response(400, [
        'success' => false,
        'message' => 'Payload must include at least one entry in simple_goods or new_goods'
    ]);
}

$ensureNumeric = function ($value, string $field, int $index, string $context) use ($response) {
    if (!is_numeric($value)) {
        $response(400, [
            'success' => false,
            'message' => "Field {$context}[{$index}].{$field} must be numeric"
        ]);
    }
    return $value;
};

$parseBoolean = function ($value, string $field, int $index, string $context) use ($response) {
    if (is_bool($value)) {
        return $value;
    }
    $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($filtered === null) {
        $response(400, [
            'success' => false,
            'message' => "Field {$context}[{$index}].{$field} must be boolean"
        ]);
    }
    return $filtered;
};

$simpleOperations = [];
foreach ($simpleItems as $index => $item) {
    if (!is_array($item)) {
        $response(400, ['success' => false, 'message' => "Invalid entry in simple_goods at index {$index}"]);
    }

    $supplierRaw = $item['supplier_id'] ?? ($item['supplierId'] ?? null);
    $goodsRaw = $item['goods_id'] ?? ($item['goodsId'] ?? null);
    $priceRaw = $item['price'] ?? null;
    $minOrderRaw = $item['min_order'] ?? ($item['minOrder'] ?? null);
    $discountPriceRaw = $item['discount_price'] ?? ($item['discountPrice'] ?? null);
    $discountStartRaw = $item['discount_start'] ?? ($item['discountStart'] ?? null);
    $isAvailableForCreditRaw = $item['is_available_for_credit'] ?? ($item['isAvailableForCredit'] ?? null);
    $isAvailableRaw = $item['is_available'] ?? ($item['isAvailable'] ?? null);
    $isUpdateRaw = $item['is_update'] ?? ($item['isUpdate'] ?? null);

    if ($supplierRaw === null) {
        $response(400, ['success' => false, 'message' => "Missing field simple_goods[{$index}].supplier_id"]);
    }
    if ($goodsRaw === null) {
        $response(400, ['success' => false, 'message' => "Missing field simple_goods[{$index}].goods_id"]);
    }
    if ($priceRaw === null) {
        $response(400, ['success' => false, 'message' => "Missing field simple_goods[{$index}].price"]);
    }
    if ($minOrderRaw === null) {
        $response(400, ['success' => false, 'message' => "Missing field simple_goods[{$index}].min_order"]);
    }
    if ($discountPriceRaw === null) {
        $response(400, ['success' => false, 'message' => "Missing field simple_goods[{$index}].discount_price"]);
    }
    if ($discountStartRaw === null) {
        $response(400, ['success' => false, 'message' => "Missing field simple_goods[{$index}].discount_start"]);
    }
    if ($isAvailableForCreditRaw === null) {
        $response(400, ['success' => false, 'message' => "Missing field simple_goods[{$index}].is_available_for_credit"]);
    }
    if ($isAvailableRaw === null) {
        $response(400, ['success' => false, 'message' => "Missing field simple_goods[{$index}].is_available"]);
    }
    if ($isUpdateRaw === null) {
        $response(400, ['success' => false, 'message' => "Missing field simple_goods[{$index}].is_update"]);
    }

    $supplierId = (int)$ensureNumeric($supplierRaw, 'supplier_id', $index, 'simple_goods');
    $goodsId = (int)$ensureNumeric($goodsRaw, 'goods_id', $index, 'simple_goods');
    $price = (int)$ensureNumeric($priceRaw, 'price', $index, 'simple_goods');
    $minOrder = (int)$ensureNumeric($minOrderRaw, 'min_order', $index, 'simple_goods');
    $discountPrice = (int)$ensureNumeric($discountPriceRaw, 'discount_price', $index, 'simple_goods');
    $discountStart = (int)$ensureNumeric($discountStartRaw, 'discount_start', $index, 'simple_goods');
    $isAvailableForCredit = (int)$ensureNumeric($isAvailableForCreditRaw, 'is_available_for_credit', $index, 'simple_goods');
    $isAvailable = (int)$ensureNumeric($isAvailableRaw, 'is_available', $index, 'simple_goods');
    $isUpdate = $parseBoolean($isUpdateRaw, 'is_update', $index, 'simple_goods');

    $simpleOperations[] = [
        'index' => $index,
        'supplier_id' => $supplierId,
        'goods_id' => $goodsId,
        'price' => $price,
        'discount_start' => $discountStart,
        'discount_price' => $discountPrice,
        'min_order' => $minOrder,
        'is_available_for_credit' => $isAvailableForCredit,
        'is_available' => $isAvailable,
        'is_update' => $isUpdate
    ];
}

$newGoodsOperations = [];
foreach ($newItems as $index => $item) {
    if (!is_array($item)) {
        $response(400, ['success' => false, 'message' => "Invalid entry in new_goods at index {$index}"]);
    }

    $requiredFields = [
        'supplier_id', 'category_id', 'brand_id', 'name', 'description',
        'priority', 'show_in_home', 'star_value', 'tiktok_url', 'commission',
        'price', 'discount_start', 'discount_price', 'min_order',
        'is_available_for_credit', 'is_available'
    ];

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $item)) {
            $response(400, ['success' => false, 'message' => "Missing field new_goods[{$index}].{$field}"]);
        }
    }

    $supplierId = (int)$ensureNumeric($item['supplier_id'], 'supplier_id', $index, 'new_goods');
    $categoryId = (int)$ensureNumeric($item['category_id'], 'category_id', $index, 'new_goods');
    $brandId = (int)$ensureNumeric($item['brand_id'], 'brand_id', $index, 'new_goods');
    $priority = (int)$ensureNumeric($item['priority'], 'priority', $index, 'new_goods');
    $showInHome = (int)$ensureNumeric($item['show_in_home'], 'show_in_home', $index, 'new_goods');
    $commission = (int)$ensureNumeric($item['commission'], 'commission', $index, 'new_goods');
    $price = (int)$ensureNumeric($item['price'], 'price', $index, 'new_goods');
    $discountStart = (int)$ensureNumeric($item['discount_start'], 'discount_start', $index, 'new_goods');
    $discountPrice = (int)$ensureNumeric($item['discount_price'], 'discount_price', $index, 'new_goods');
    $minOrder = (int)$ensureNumeric($item['min_order'], 'min_order', $index, 'new_goods');
    $isAvailableForCredit = (int)$ensureNumeric($item['is_available_for_credit'], 'is_available_for_credit', $index, 'new_goods');
    $isAvailable = (int)$ensureNumeric($item['is_available'], 'is_available', $index, 'new_goods');

    $newGoodsOperations[] = [
        'index' => $index,
        'supplier_id' => $supplierId,
        'category_id' => $categoryId,
        'brand_id' => $brandId,
        'name' => (string)$item['name'],
        'description' => (string)$item['description'],
        'priority' => $priority,
        'show_in_home' => $showInHome,
        'star_value' => (string)$item['star_value'],
        'tiktok_url' => (string)$item['tiktok_url'],
        'commission' => $commission,
        'price' => $price,
        'discount_start' => $discountStart,
        'discount_price' => $discountPrice,
        'min_order' => $minOrder,
        'is_available_for_credit' => $isAvailableForCredit,
        'is_available' => $isAvailable,
        'image_url' => isset($item['image_url']) ? (string)$item['image_url'] : ''
    ];
}

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $settings = new Settings($db);
    $goodsModel = new Goods($db);
    $sgModel = new SupplierGoods($db);

    $supplierCache = [];
    $goodsCache = [];
    $categoryCache = [];
    $brandCache = [];

    $categoryLookup = $db->prepare('SELECT 1 FROM category WHERE id = :id');
    $brandLookup = $db->prepare('SELECT 1 FROM brand WHERE id = :id');

    foreach ($simpleOperations as $op) {
        if (!array_key_exists($op['supplier_id'], $supplierCache)) {
            $supplierCache[$op['supplier_id']] = $sgModel->supplierExists($op['supplier_id']);
        }
        if (!$supplierCache[$op['supplier_id']]) {
            $response(404, [
                'success' => false,
                'message' => 'Supplier not found',
                'supplier_id' => $op['supplier_id'],
                'context' => 'simple_goods',
                'index' => $op['index']
            ]);
        }

        if (!array_key_exists($op['goods_id'], $goodsCache)) {
            $goodsCache[$op['goods_id']] = $sgModel->goodsExists($op['goods_id']);
        }
        if (!$goodsCache[$op['goods_id']]) {
            $response(404, [
                'success' => false,
                'message' => 'Goods not found',
                'goods_id' => $op['goods_id'],
                'context' => 'simple_goods',
                'index' => $op['index']
            ]);
        }
    }

    foreach ($newGoodsOperations as $op) {
        if (!array_key_exists($op['supplier_id'], $supplierCache)) {
            $supplierCache[$op['supplier_id']] = $sgModel->supplierExists($op['supplier_id']);
        }
        if (!$supplierCache[$op['supplier_id']]) {
            $response(404, [
                'success' => false,
                'message' => 'Supplier not found',
                'supplier_id' => $op['supplier_id'],
                'context' => 'new_goods',
                'index' => $op['index']
            ]);
        }

        if (!array_key_exists($op['category_id'], $categoryCache)) {
            $categoryLookup->execute([':id' => $op['category_id']]);
            $categoryCache[$op['category_id']] = (bool)$categoryLookup->fetchColumn();
            $categoryLookup->closeCursor();
        }
        if (!$categoryCache[$op['category_id']]) {
            $response(404, [
                'success' => false,
                'message' => 'Category not found',
                'category_id' => $op['category_id'],
                'context' => 'new_goods',
                'index' => $op['index']
            ]);
        }

        if (!array_key_exists($op['brand_id'], $brandCache)) {
            $brandLookup->execute([':id' => $op['brand_id']]);
            $brandCache[$op['brand_id']] = (bool)$brandLookup->fetchColumn();
            $brandLookup->closeCursor();
        }
        if (!$brandCache[$op['brand_id']]) {
            $response(404, [
                'success' => false,
                'message' => 'Brand not found',
                'brand_id' => $op['brand_id'],
                'context' => 'new_goods',
                'index' => $op['index']
            ]);
        }
    }

    $db->beginTransaction();

    $touchCategory = $db->prepare('UPDATE category SET last_update_code = :code WHERE id = :id');

    $results = [
        'new_goods' => [],
        'simple_goods' => []
    ];

    foreach ($newGoodsOperations as $op) {
        $code = (int)$settings->nextCode();

        $goodsPayload = [
            'category_id' => $op['category_id'],
            'brand_id' => $op['brand_id'],
            'name' => $op['name'],
            'description' => $op['description'],
            'priority' => $op['priority'],
            'show_in_home' => $op['show_in_home'],
            'image_url' => $op['image_url'],
            'last_update_code' => $code,
            'star_value' => $op['star_value'],
            'tiktok_url' => $op['tiktok_url'],
            'commission' => $op['commission']
        ];
        $goodsId = $goodsModel->createGoods($goodsPayload);

        $touchCategory->execute([
            ':code' => $code,
            ':id' => $op['category_id']
        ]);

        $relationPayload = [
            'supplier_id' => $op['supplier_id'],
            'goods_id' => $goodsId,
            'price' => $op['price'],
            'discount_start' => $op['discount_start'],
            'discount_price' => $op['discount_price'],
            'min_order' => $op['min_order'],
            'is_available_for_credit' => $op['is_available_for_credit'],
            'is_available' => $op['is_available']
        ];
        $relationResult = $sgModel->upsertRelation($relationPayload, $code);

        $results['new_goods'][] = [
            'index' => $op['index'],
            'goods_id' => $goodsId,
            'supplier_id' => $op['supplier_id'],
            'last_update_code' => $code,
            'relation' => $relationResult,
            'image_url' => $op['image_url']
        ];
    }

    foreach ($simpleOperations as $op) {
        $code = (int)$settings->nextCode();
        $existing = $sgModel->findBySupplierAndGoods($op['supplier_id'], $op['goods_id']);

        if ($op['is_update']) {
            if (!$existing) {
                $db->rollBack();
                $response(404, [
                    'success' => false,
                    'message' => 'Supplier goods relation not found for update',
                    'supplier_id' => $op['supplier_id'],
                    'goods_id' => $op['goods_id'],
                    'index' => $op['index']
                ]);
            }
        } else {
            if ($existing) {
                $db->rollBack();
                $response(409, [
                    'success' => false,
                    'message' => 'Supplier goods relation already exists',
                    'supplier_id' => $op['supplier_id'],
                    'goods_id' => $op['goods_id'],
                    'index' => $op['index']
                ]);
            }
        }

        $relationPayload = [
            'supplier_id' => $op['supplier_id'],
            'goods_id' => $op['goods_id'],
            'price' => $op['price'],
            'discount_start' => $op['discount_start'],
            'discount_price' => $op['discount_price'],
            'min_order' => $op['min_order'],
            'is_available_for_credit' => $op['is_available_for_credit'],
            'is_available' => $op['is_available']
        ];
        $relationResult = $sgModel->upsertRelation($relationPayload, $code);

        $results['simple_goods'][] = [
            'index' => $op['index'],
            'supplier_id' => $op['supplier_id'],
            'goods_id' => $op['goods_id'],
            'action' => $op['is_update'] ? 'update' : 'insert',
            'last_update_code' => $code,
            'relation' => $relationResult
        ];
    }

    $db->commit();

    $response(200, [
        'success' => true,
        'message' => 'Batch payload processed successfully',
        'processed_new_goods' => count($results['new_goods']),
        'processed_simple_goods' => count($results['simple_goods']),
        'results' => $results
    ]);
} catch (PDOException $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $response(500, [
        'success' => false,
        'message' => 'Database error',
        'error' => $e->getMessage()
    ]);
} catch (Throwable $t) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $response(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $t->getMessage()
    ]);
}