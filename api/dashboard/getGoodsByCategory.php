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
$limitPlusOne = $limit + 1;
$rawSearch = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
$searchTerm = $rawSearch !== '' ? '%' . $rawSearch . '%' : null;
$searchBindings = [];

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $goodsQuery = 'SELECT
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
        WHERE g.category_id = :categoryId';

    if ($searchTerm !== null) {
        $goodsQuery .= ' AND (
            g.name LIKE :search_name
            OR g.description LIKE :search_description
            OR c.name LIKE :search_category
            OR EXISTS (
                SELECT 1
                FROM supplier_goods sg2
                INNER JOIN supplier s2 ON s2.shop_id = sg2.supplier_id
                WHERE sg2.goods_id = g.id
                  AND sg2.is_available = 1
                  AND (
                    s2.shop_name LIKE :search_supplier_name
                    OR s2.shop_type LIKE :search_supplier_type
                  )
            )
        )';
        $searchBindings = [
            ':search_name' => $searchTerm,
            ':search_description' => $searchTerm,
            ':search_category' => $searchTerm,
            ':search_supplier_name' => $searchTerm,
            ':search_supplier_type' => $searchTerm
        ];
    }

    $goodsQuery .= ' ORDER BY g.priority DESC, g.id DESC
        LIMIT :limitPlusOne OFFSET :offset';

    $goodsStmt = $db->prepare($goodsQuery);
    $goodsStmt->bindValue(':categoryId', $categoryId, PDO::PARAM_INT);
    foreach ($searchBindings as $placeholder => $value) {
        $goodsStmt->bindValue($placeholder, $value, PDO::PARAM_STR);
    }
    $goodsStmt->bindValue(':limitPlusOne', $limitPlusOne, PDO::PARAM_INT);
    $goodsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $goodsStmt->execute();

    $goodsRows = $goodsStmt->fetchAll();
    $hasMore = count($goodsRows) > $limit;
    if ($hasMore) {
        $goodsRows = array_slice($goodsRows, 0, $limit);
    }

    if (!$goodsRows) {
        echo json_encode([
            'success' => true,
            'goods' => [],
            'count' => 0,
            'hasMore' => false,
            'nextOffset' => $offset
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
        ORDER BY sg.goods_id ASC, sg.price ASC, sg.min_order ASC"
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
        'count' => count($responseGoods),
        'hasMore' => $hasMore,
        'nextOffset' => $offset + count($responseGoods)
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
