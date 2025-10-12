<?php
header('Content-Type: application/json');

include_once '../../config/Database.php';
include_once '../../model/Settings.php';
include_once '../../model/Goods.php';
include_once '../../model/SupplierGoods.php';

$response = function(int $code, array $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response(405, ['success' => false, 'message' => 'Method not allowed']);
}

// Validate and collect multipart fields
if (!isset($_FILES['image'])) {
    $response(400, ['success' => false, 'message' => 'Image file is required']);
}

$allowedExt = ['jpg','jpeg','png']; // only jpg and png
$maxSize = 500 * 1024; // 500KB

$img = $_FILES['image'];
if ($img['error'] !== UPLOAD_ERR_OK) {
    $response(400, ['success' => false, 'message' => 'Image upload error', 'error_code' => $img['error']]);
}
if ($img['size'] > $maxSize) {
    $response(400, ['success' => false, 'message' => 'Image exceeds 500KB']);
}
$ext = strtolower(pathinfo($img['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    $response(400, ['success' => false, 'message' => 'Only jpg and png are allowed']);
}

// Required goods fields (all NOT NULL columns per schema)
$requiredGoods = ['category_id','brand_id','name','description','priority','show_in_home','star_value','tiktok_url','commission'];
$goods = [];
foreach ($requiredGoods as $k) {
    if (!isset($_POST[$k])) {
        $response(400, ['success' => false, 'message' => "Missing field: $k"]);
    }
    $goods[$k] = $_POST[$k];
}

// Required supplier and basic relation fields
if (!isset($_POST['supplier_id'])) {
    $response(400, ['success' => false, 'message' => 'Missing field: supplier_id']);
}
$supplierId = (int)$_POST['supplier_id'];

// Optional supplier_goods fields with defaults
$sg = [];
$sg['price'] = isset($_POST['price']) ? (int)$_POST['price'] : 0;
$sg['discount_start'] = isset($_POST['discount_start']) ? (int)$_POST['discount_start'] : 0;
$sg['discount_price'] = isset($_POST['discount_price']) ? (int)$_POST['discount_price'] : 0;
$sg['min_order'] = isset($_POST['min_order']) ? (int)$_POST['min_order'] : 1;
$sg['is_available_for_credit'] = isset($_POST['is_available_for_credit']) ? (int)$_POST['is_available_for_credit'] : 0;
$sg['is_available'] = isset($_POST['is_available']) ? (int)$_POST['is_available'] : 1;

// Prepare image destination
$rand = random_int(100000, 999999);
$filename = 'goods_' . time() . '_' . $rand . '.' . $ext;
$baseDir = realpath(__DIR__ . '/../../');
if ($baseDir === false) {
    $response(500, ['success' => false, 'message' => 'Base directory resolution failed']);
}
$imagesDirFs = $baseDir . DIRECTORY_SEPARATOR . 'images';
if (!is_dir($imagesDirFs)) {
    if (!mkdir($imagesDirFs, 0775, true) && !is_dir($imagesDirFs)) {
        $response(500, ['success' => false, 'message' => 'Failed to create images directory']);
    }
}
$destPath = $imagesDirFs . DIRECTORY_SEPARATOR . $filename;
$imageUrl = 'images/' . $filename; // store relative path

// DB operations
try {
    $database = new Database();
    $db = $database->connect();
    // Use exceptions
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $settings = new Settings($db);
    $goodsModel = new Goods($db);
    $sgModel = new SupplierGoods($db);

    // Basic existence checks
    if (!$sgModel->supplierExists($supplierId)) {
        $response(404, ['success' => false, 'message' => 'Supplier not found', 'supplier_id' => $supplierId]);
    }
    // Validate category and brand existence to avoid FK error
    $chk = $db->prepare('SELECT 1 FROM category WHERE id = :id');
    $chk->execute([':id' => (int)$goods['category_id']]);
    if (!$chk->fetchColumn()) {
        $response(404, ['success' => false, 'message' => 'Category not found', 'category_id' => (int)$goods['category_id']]);
    }
    $chk = $db->prepare('SELECT 1 FROM brand WHERE id = :id');
    $chk->execute([':id' => (int)$goods['brand_id']]);
    if (!$chk->fetchColumn()) {
        $response(404, ['success' => false, 'message' => 'Brand not found', 'brand_id' => (int)$goods['brand_id']]);
    }

    // Start transaction for atomicity and to support SELECT ... FOR UPDATE
    $db->beginTransaction();

    // Persist image to disk before DB commit to ensure path is valid; if DB fails, we can unlink
    if (!move_uploaded_file($img['tmp_name'], $destPath)) {
        if ($db->inTransaction()) { $db->rollBack(); }
        $response(500, ['success' => false, 'message' => 'Failed to save image']);
    }

    // Get incremented last_update_code
    $code = $settings->nextCode();

    // Create goods
    $goodsInsert = [
        'category_id' => (int)$goods['category_id'],
        'brand_id' => (int)$goods['brand_id'],
        'name' => (string)$goods['name'],
        'description' => (string)$goods['description'],
        'priority' => (int)$goods['priority'],
        'show_in_home' => (int)$goods['show_in_home'],
        'image_url' => $imageUrl,
        'last_update_code' => (int)$code,
        'star_value' => (string)$goods['star_value'],
        'tiktok_url' => (string)$goods['tiktok_url'],
        'commission' => (int)$goods['commission']
    ];
    $goodsId = $goodsModel->createGoods($goodsInsert);

    // Upsert supplier_goods relation with same last_update_code
    $upsertRes = $sgModel->upsertRelation([
        'supplier_id' => $supplierId,
        'goods_id' => $goodsId,
        'price' => $sg['price'],
        'discount_start' => $sg['discount_start'],
        'discount_price' => $sg['discount_price'],
        'min_order' => $sg['min_order'],
        'is_available_for_credit' => $sg['is_available_for_credit'],
        'is_available' => $sg['is_available']
    ], (int)$code);

    $db->commit();

    $response(200, [
        'success' => true,
        'message' => 'Goods created and linked to supplier',
        'goods_id' => $goodsId,
        'supplier_id' => $supplierId,
        'relation' => $upsertRes,
        'last_update_code' => (int)$code,
        'image_url' => $imageUrl
    ]);
} catch (PDOException $e) {
    if (isset($destPath) && file_exists($destPath)) {
        // In case of DB failure after file move, consider keeping or removing the file.
        // Here we keep it to not lose the uploaded asset, but you can unlink($destPath) if desired.
    }
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $response(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    if (isset($destPath) && file_exists($destPath)) {
        // same note as above
    }
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $response(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}
