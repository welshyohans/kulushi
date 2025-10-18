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
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    $response(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

// Normalise payload: accept a single object or an array of objects.
$payloads = [];
if (array_keys($decoded) === range(0, count($decoded) - 1)) {
    $payloads = $decoded;
} elseif (array_key_exists('items', $decoded) && is_array($decoded['items'])) {
    $payloads = $decoded['items'];
} else {
    $payloads = [$decoded];
}

if (count($payloads) === 0) {
    $response(400, ['success' => false, 'message' => 'No operations supplied']);
}

// Validate upfront and normalise field casing.
$operations = [];
foreach ($payloads as $index => $item) {
    if (!is_array($item)) {
        $response(400, ['success' => false, 'message' => "Invalid entry at index {$index}"]);
    }

    $supplier = $item['supplierId'] ?? $item['supplier_id'] ?? null;
    $goods = $item['goodsId'] ?? $item['goods_id'] ?? null;
    $price = $item['price'] ?? null;
    $minOrderValue = $item['min_order'] ?? $item['minOrder'] ?? null;
    $discountPriceValue = $item['discount_price'] ?? $item['discountPrice'] ?? null;
    $discountStartValue = $item['discount_start'] ?? $item['discountStart'] ?? null;
    $isUpdateRaw = $item['is_update'] ?? $item['isUpdate'] ?? null;

    if ($supplier === null) {
        $response(400, ['success' => false, 'message' => "Missing supplierId at index {$index}"]);
    }
    if ($goods === null) {
        $response(400, ['success' => false, 'message' => "Missing goodsId at index {$index}"]);
    }
    if ($price === null) {
        $response(400, ['success' => false, 'message' => "Missing price at index {$index}"]);
    }
    if ($minOrderValue === null) {
        $response(400, ['success' => false, 'message' => "Missing minOrder at index {$index}"]);
    }
    if ($discountPriceValue === null) {
        $response(400, ['success' => false, 'message' => "Missing discountPrice at index {$index}"]);
    }
    if ($discountStartValue === null) {
        $response(400, ['success' => false, 'message' => "Missing discountStart at index {$index}"]);
    }
    if ($isUpdateRaw === null) {
        $response(400, ['success' => false, 'message' => "Missing isUpdate flag at index {$index}"]);
    }

    if (is_bool($isUpdateRaw)) {
        $isUpdate = $isUpdateRaw;
    } else {
        $filtered = filter_var($isUpdateRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($filtered === null) {
            $response(400, ['success' => false, 'message' => "Invalid isUpdate flag at index {$index}"]);
        }
        $isUpdate = $filtered;
    }

    $discountStart = $discountStartValue;
    $discountPrice = $discountPriceValue;
    $minOrder = $minOrderValue;
    $isAvailableForCredit = $item['is_available_for_credit'] ?? null;
    $isAvailable = $item['is_available'] ?? null;

    $operations[] = [
        'index' => $index,
        'supplier_id' => (int)$supplier,
        'goods_id' => (int)$goods,
        'price' => (int)$price,
        'is_update' => $isUpdate,
        'discount_start' => (int)$discountStart,
        'discount_price' => (int)$discountPrice,
        'min_order' => (int)$minOrder,
        'is_available_for_credit' => $isAvailableForCredit !== null ? (int)$isAvailableForCredit : null,
        'is_available' => $isAvailable !== null ? (int)$isAvailable : null
    ];
}

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $settings = new Settings($db);
    $sgModel = new SupplierGoods($db);

    $supplierCache = [];
    $goodsCache = [];

    foreach ($operations as $op) {
        if (!array_key_exists($op['supplier_id'], $supplierCache)) {
            $supplierCache[$op['supplier_id']] = $sgModel->supplierExists($op['supplier_id']);
        }
        if (!array_key_exists($op['goods_id'], $goodsCache)) {
            $goodsCache[$op['goods_id']] = $sgModel->goodsExists($op['goods_id']);
        }

        if (!$supplierCache[$op['supplier_id']]) {
            $response(404, [
                'success' => false,
                'message' => 'Supplier not found',
                'supplier_id' => $op['supplier_id'],
                'index' => $op['index']
            ]);
        }
        if (!$goodsCache[$op['goods_id']]) {
            $response(404, [
                'success' => false,
                'message' => 'Goods not found',
                'goods_id' => $op['goods_id'],
                'index' => $op['index']
            ]);
        }
    }

    $db->beginTransaction();

    $code = $settings->nextCode();
    $results = [];

    foreach ($operations as $op) {
        $existingRelation = $sgModel->findBySupplierAndGoods($op['supplier_id'], $op['goods_id']);

        if ($op['is_update']) {
            if (!$existingRelation) {
                $db->rollBack();
                $response(404, [
                    'success' => false,
                    'message' => 'Supplier goods relation not found for update',
                    'supplier_id' => $op['supplier_id'],
                    'goods_id' => $op['goods_id'],
                    'index' => $op['index']
                ]);
            }

            $updateFields = [
                'price' => $op['price'],
                'discount_price' => $op['discount_price'] !== null ? $op['discount_price'] : $op['price']
            ];
            if ($op['discount_start'] !== null) {
                $updateFields['discount_start'] = $op['discount_start'];
            }
            if ($op['min_order'] !== null) {
                $updateFields['min_order'] = $op['min_order'];
            }
            if ($op['is_available_for_credit'] !== null) {
                $updateFields['is_available_for_credit'] = $op['is_available_for_credit'];
            }
            if ($op['is_available'] !== null) {
                $updateFields['is_available'] = $op['is_available'];
            }

            $affected = $sgModel->updateCoreFields(
                $op['supplier_id'],
                $op['goods_id'],
                $updateFields,
                (int)$code
            );

            $results[] = [
                'index' => $op['index'],
                'action' => 'update',
                'supplier_id' => $op['supplier_id'],
                'goods_id' => $op['goods_id'],
                'affected' => $affected
            ];
        } else {
            if ($existingRelation) {
                $db->rollBack();
                $response(409, [
                    'success' => false,
                    'message' => 'Supplier goods relation already exists',
                    'supplier_id' => $op['supplier_id'],
                    'goods_id' => $op['goods_id'],
                    'index' => $op['index']
                ]);
            }

            $newId = $sgModel->insertSimpleRelation(
                $op['supplier_id'],
                $op['goods_id'],
                $op['price'],
                (int)$code,
                [
                    'discount_start' => $op['discount_start'],
                    'discount_price' => $op['discount_price'] ?? $op['price'],
                    'min_order' => $op['min_order'],
                    'is_available_for_credit' => $op['is_available_for_credit'],
                    'is_available' => $op['is_available']
                ]
            );

            $results[] = [
                'index' => $op['index'],
                'action' => 'insert',
                'supplier_id' => $op['supplier_id'],
                'goods_id' => $op['goods_id'],
                'supplier_goods_id' => $newId
            ];
        }
    }

    $db->commit();

    $response(200, [
        'success' => true,
        'message' => 'Supplier goods processed successfully',
        'last_update_code' => (int)$code,
        'results' => $results
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
