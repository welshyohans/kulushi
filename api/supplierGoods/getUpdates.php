<?php
header('Content-Type: application/json');

include_once '../../config/Database.php';
include_once '../../model/SupplierGoods.php';
include_once '../../model/Settings.php';

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

$supplierId = $data['supplierId'] ?? $data['supplier_id'] ?? null;
$lastUpdateCode = $data['lastUpdateCode'] ?? $data['last_update_code'] ?? null;

if ($supplierId === null) {
    $response(400, ['success' => false, 'message' => 'Missing field: supplierId']);
}
if ($lastUpdateCode === null) {
    $response(400, ['success' => false, 'message' => 'Missing field: lastUpdateCode']);
}

$supplierId = (int)$supplierId;
$lastUpdateCode = (int)$lastUpdateCode;

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sgModel = new SupplierGoods($db);
    $settings = new Settings($db);
    if (!$sgModel->supplierExists($supplierId)) {
        $response(404, ['success' => false, 'message' => 'Supplier not found', 'supplier_id' => $supplierId]);
    }

    $goodsStmt = $db->prepare('SELECT * FROM goods WHERE last_update_code > :code ORDER BY last_update_code ASC');
    $goodsStmt->execute([':code' => $lastUpdateCode]);
    $goods = $goodsStmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = [];
    $categoryStmt = $db->prepare('SELECT id, name FROM category WHERE last_update_code > :code ORDER BY last_update_code ASC');
    $categoryStmt->execute([':code' => $lastUpdateCode]);
    foreach ($categoryStmt->fetchAll(PDO::FETCH_ASSOC) as $categoryRow) {
        $categories[] = [
            'id' => isset($categoryRow['id']) ? (int)$categoryRow['id'] : 0,
            'name' => isset($categoryRow['name']) ? (string)$categoryRow['name'] : '',
        ];
    }

    $supplierGoodsStmt = $db->prepare('SELECT * FROM supplier_goods WHERE supplier_id = :sid ORDER BY last_update_code ASC, id ASC');
    $supplierGoodsStmt->execute([':sid' => $supplierId]);
    $supplierGoods = $supplierGoodsStmt->fetchAll(PDO::FETCH_ASSOC);

    $currentCode = (int)$settings->getValue('last_update_code', 0);

    $response(200, [
        'success' => true,
        'supplier_id' => $supplierId,
        'requested_last_update_code' => $lastUpdateCode,
        'current_last_update_code' => $currentCode,
        'goods_updates' => $goods,
        'supplier_goods' => $supplierGoods,
        'categories' => $categories
    ]);
} catch (PDOException $e) {
    $response(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    $response(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}
