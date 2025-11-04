<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
    exit;
}

if (!isset($_GET['goodsId'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'goodsId parameter is required.'
    ]);
    exit;
}

$goodsId = (int)$_GET['goodsId'];
if ($goodsId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'goodsId must be a positive integer.'
    ]);
    exit;
}

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $stmt = $db->prepare(
        'SELECT
            sg.id AS supplier_goods_id,
            sg.goods_id,
            sg.price,
            sg.discount_price,
            sg.discount_start,
            sg.min_order,
            sg.is_available_for_credit,
            sg.is_available,
            sg.last_update_code,
            s.shop_id,
            s.shop_name,
            s.shop_type,
            s.phone,
            s.priority,
            s.image AS supplier_image
        FROM supplier_goods sg
        INNER JOIN supplier s ON s.shop_id = sg.supplier_id
        WHERE sg.goods_id = :goodsId
        ORDER BY sg.price ASC'
    );
    $stmt->execute([':goodsId' => $goodsId]);
    $rows = $stmt->fetchAll();

    $options = [];
    foreach ($rows as $row) {
        $options[] = [
            'supplierGoodsId' => (int)$row['supplier_goods_id'],
            'goodsId' => (int)$row['goods_id'],
            'price' => isset($row['price']) ? (float)$row['price'] : null,
            'discountPrice' => isset($row['discount_price']) ? (float)$row['discount_price'] : null,
            'discountStart' => isset($row['discount_start']) ? (int)$row['discount_start'] : null,
            'minOrder' => (int)$row['min_order'],
            'isAvailableForCredit' => (int)$row['is_available_for_credit'] === 1,
            'isAvailable' => (int)$row['is_available'] === 1,
            'lastUpdateCode' => (int)$row['last_update_code'],
            'shopId' => (int)$row['shop_id'],
            'shopName' => $row['shop_name'] ?? '',
            'shopType' => $row['shop_type'] ?? '',
            'phone' => $row['phone'] ?? '',
            'priority' => isset($row['priority']) ? (int)$row['priority'] : null,
            'image' => $row['supplier_image'] ?? ''
        ];
    }

    echo json_encode([
        'success' => true,
        'options' => $options,
        'count' => count($options)
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error while fetching supplier goods options.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}