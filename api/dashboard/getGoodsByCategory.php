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

if (!isset($_GET['categoryId'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'categoryId parameter is required.'
    ]);
    exit;
}

$categoryId = (int)$_GET['categoryId'];
if ($categoryId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'categoryId must be a positive integer.'
    ]);
    exit;
}

$limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 50)) : 20;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $goodsStmt = $db->prepare(
        'SELECT
            g.id AS goods_id,
            g.name,
            g.description,
            g.priority,
            g.show_in_home,
            g.image_url,
            g.category_id,
            g.commission,
            c.name AS category_name
        FROM goods g
        LEFT JOIN category c ON c.id = g.category_id
        WHERE g.category_id = :categoryId
        ORDER BY g.priority DESC, g.id DESC
        LIMIT :limit OFFSET :offset'
    );
    $goodsStmt->bindValue(':categoryId', $categoryId, PDO::PARAM_INT);
    $goodsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $goodsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $goodsStmt->execute();

    $goodsRows = $goodsStmt->fetchAll();
    if (!$goodsRows) {
        echo json_encode([
            'success' => true,
            'goods' => [],
            'count' => 0
        ]);
        exit;
    }

    $goodsIds = array_column($goodsRows, 'goods_id');
    $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));

    $offersStmt = $db->prepare(
        "SELECT
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
            s.image AS supplier_image
        FROM supplier_goods sg
        INNER JOIN supplier s ON s.shop_id = sg.supplier_id
        WHERE sg.is_available = 1
          AND sg.goods_id IN ($placeholders)
        ORDER BY sg.goods_id ASC, sg.min_order ASC, sg.price ASC"
    );
    $offersStmt->execute($goodsIds);
    $offers = $offersStmt->fetchAll();

    $offerMap = [];
    foreach ($offers as $offer) {
        $goodsKey = (int)$offer['goods_id'];
        if (!isset($offerMap[$goodsKey])) {
            $offerMap[$goodsKey] = [];
        }
        $offerMap[$goodsKey][] = [
            'supplierGoodsId' => (int)$offer['supplier_goods_id'],
            'goodsId' => $goodsKey,
            'price' => (float)$offer['price'],
            'discountPrice' => isset($offer['discount_price']) ? (float)$offer['discount_price'] : null,
            'discountStart' => isset($offer['discount_start']) ? (int)$offer['discount_start'] : null,
            'minOrder' => (int)$offer['min_order'],
            'isAvailableForCredit' => (int)$offer['is_available_for_credit'] === 1,
            'isAvailable' => (int)$offer['is_available'] === 1,
            'lastUpdateCode' => (int)$offer['last_update_code'],
            'shopId' => (int)$offer['shop_id'],
            'shopName' => $offer['shop_name'] ?? '',
            'shopType' => $offer['shop_type'] ?? '',
            'phone' => $offer['phone'] ?? '',
            'image' => $offer['supplier_image'] ?? ''
        ];
    }

    $responseGoods = [];
    foreach ($goodsRows as $row) {
        $goodsKey = (int)$row['goods_id'];
        $supplierOffers = $offerMap[$goodsKey] ?? [];

        if (count($supplierOffers) === 0) {
            continue;
        }

        $primarySupplier = $supplierOffers[0] ?? null;

        $responseGoods[] = [
            'goodsId' => $goodsKey,
            'name' => $row['name'] ?? '',
            'description' => $row['description'] ?? '',
            'priority' => isset($row['priority']) ? (int)$row['priority'] : 0,
            'showInHome' => (bool)$row['show_in_home'],
            'imageUrl' => $row['image_url'] ?? '',
            'categoryId' => isset($row['category_id']) ? (int)$row['category_id'] : null,
            'categoryName' => $row['category_name'] ?? '',
            'commission' => isset($row['commission']) ? (float)$row['commission'] : 0.0,
            'lowestPrice' => $primarySupplier ? $primarySupplier['price'] : null,
            'supplierName' => $primarySupplier['shopName'] ?? '',
            'primarySupplier' => $primarySupplier,
            'supplierOffers' => $supplierOffers
        ];
    }

    echo json_encode([
        'success' => true,
        'goods' => $responseGoods,
        'count' => count($responseGoods)
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error while fetching goods.',
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
