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

if (!isset($_FILES['image'])) {
    $response(400, ['success' => false, 'message' => 'Image file is required']);
}

$img = $_FILES['image'];
if ($img['error'] !== UPLOAD_ERR_OK) {
    $response(400, ['success' => false, 'message' => 'Image upload error', 'error_code' => $img['error']]);
}

$allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
$maxSize = 2 * 1024 * 1024;

if ($img['size'] > $maxSize) {
    $response(400, ['success' => false, 'message' => 'Image exceeds 2MB']);
}

$ext = strtolower(pathinfo($img['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    $response(400, ['success' => false, 'message' => 'Only jpg, jpeg, png, webp images are allowed']);
}

$supplierIdRaw = $_POST['supplierId'] ?? $_POST['supplier_id'] ?? null;
$goodsIdRaw = $_POST['goodsId'] ?? $_POST['goods_id'] ?? null;

if ($supplierIdRaw === null || $supplierIdRaw === '') {
    $response(400, ['success' => false, 'message' => 'Missing field: supplierId']);
}
if ($goodsIdRaw === null || $goodsIdRaw === '') {
    $response(400, ['success' => false, 'message' => 'Missing field: goodsId']);
}

$supplierId = filter_var($supplierIdRaw, FILTER_VALIDATE_INT);
$goodsId = filter_var($goodsIdRaw, FILTER_VALIDATE_INT);

if ($supplierId === false || $supplierId <= 0) {
    $response(400, ['success' => false, 'message' => 'Invalid supplierId']);
}
if ($goodsId === false || $goodsId <= 0) {
    $response(400, ['success' => false, 'message' => 'Invalid goodsId']);
}

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

if (!is_uploaded_file($img['tmp_name'])) {
    $response(400, ['success' => false, 'message' => 'Invalid image upload payload']);
}

$filename = 'goods_' . $goodsId . '_' . time() . '_' . random_int(100000, 999999) . '.' . $ext;
$destPath = $imagesDirFs . DIRECTORY_SEPARATOR . $filename;
$imageUrl = 'images/' . $filename;

$fileMoved = false;
$destPathResolved = null;
$oldImageRelative = null;

try {
    $database = new Database();
    $db = $database->connect();
    if (!$db instanceof PDO) {
        $response(500, ['success' => false, 'message' => 'Database connection failed']);
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $settings = new Settings($db);
    $sgModel = new SupplierGoods($db);

    if (!$sgModel->supplierExists((int)$supplierId)) {
        $response(404, ['success' => false, 'message' => 'Supplier not found', 'supplier_id' => (int)$supplierId]);
    }
    if (!$sgModel->goodsExists((int)$goodsId)) {
        $response(404, ['success' => false, 'message' => 'Goods not found', 'goods_id' => (int)$goodsId]);
    }
    if (!$sgModel->findBySupplierAndGoods((int)$supplierId, (int)$goodsId)) {
        $response(404, ['success' => false, 'message' => 'Supplier-goods relation not found', 'supplier_id' => (int)$supplierId, 'goods_id' => (int)$goodsId]);
    }

    $db->beginTransaction();

    $goodsStmt = $db->prepare('SELECT image_url FROM goods WHERE id = :id FOR UPDATE');
    $goodsStmt->execute([':id' => (int)$goodsId]);
    $goodsRow = $goodsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$goodsRow) {
        $db->rollBack();
        $response(404, ['success' => false, 'message' => 'Goods not found', 'goods_id' => (int)$goodsId]);
    }
    $oldImageRelative = $goodsRow['image_url'] ?? null;

    if (!move_uploaded_file($img['tmp_name'], $destPath)) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $response(500, ['success' => false, 'message' => 'Failed to save image']);
    }
    $fileMoved = true;
    $destPathResolved = realpath($destPath) ?: $destPath;

    $code = (int)$settings->nextCode();

    $updateGoods = $db->prepare('UPDATE goods SET image_url = :image_url, last_update_code = :code, last_update = NOW() WHERE id = :id');
    $updateGoods->execute([
        ':image_url' => $imageUrl,
        ':code' => $code,
        ':id' => (int)$goodsId
    ]);

    $touchRelation = $db->prepare('UPDATE supplier_goods SET last_update_code = :code WHERE supplier_id = :sid AND goods_id = :gid');
    $touchRelation->execute([
        ':code' => $code,
        ':sid' => (int)$supplierId,
        ':gid' => (int)$goodsId
    ]);

    $touchSupplier = $db->prepare('UPDATE supplier SET last_update_code = :code WHERE shop_id = :sid');
    $touchSupplier->execute([
        ':code' => $code,
        ':sid' => (int)$supplierId
    ]);

    $historyStmt = $db->prepare('INSERT INTO supplier_history (supplier_id, goods_id, action, details, image_path, created_at) VALUES (:supplier_id, :goods_id, :action, :details, :image_path, NOW())');
    $historyStmt->execute([
        ':supplier_id' => (int)$supplierId,
        ':goods_id' => (int)$goodsId,
        ':action' => 'upload_image',
        ':details' => 'Supplier uploaded goods image',
        ':image_path' => $imageUrl
    ]);
    $historyId = (int)$db->lastInsertId();

    $db->commit();

    if ($oldImageRelative && $oldImageRelative !== $imageUrl) {
        $oldImageFullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $oldImageRelative);
        if (is_file($oldImageFullPath)) {
            $resolvedOld = realpath($oldImageFullPath);
            if ($resolvedOld !== false && strpos($resolvedOld, $imagesDirFs) === 0 && $destPathResolved !== $resolvedOld) {
                @unlink($resolvedOld);
            }
        }
    }

    $response(200, [
        'success' => true,
        'message' => 'Image received and goods updated',
        'supplier_id' => (int)$supplierId,
        'goods_id' => (int)$goodsId,
        'image_url' => $imageUrl,
        'last_update_code' => $code,
        'history_id' => $historyId
    ]);
} catch (PDOException $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($fileMoved && file_exists($destPath)) {
        @unlink($destPath);
    }
    $response(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($fileMoved && file_exists($destPath)) {
        @unlink($destPath);
    }
    $response(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}

?>