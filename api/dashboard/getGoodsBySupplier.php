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

if (!isset($_GET['supplierId'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'supplierId parameter is required.'
    ]);
    exit;
}

$supplierId = (int)$_GET['supplierId'];
if ($supplierId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'supplierId must be a positive integer.'
    ]);
    exit;
}

$limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 50)) : 20;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$limitPlusOne = $limit + 1;
$rawSearch = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
$searchTerm = $rawSearch !== '' ? '%' . $rawSearch . '%' : null;

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $supplierStmt = $db->prepare('SELECT shop_id, shop_name, shop_type, phone FROM supplier WHERE shop_id = :supplierId LIMIT 1');
    $supplierStmt->execute([':supplierId' => $supplierId]);
    $supplier = $supplierStmt->fetch();

    if (!$supplier) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Supplier not found.'
        ]);
        exit;
    }

    $goodsQuery = 'SELECT
            sg.id AS supplier_goods_id,
            sg.goods_id,
            sg.price,
            sg.discount_price,
            sg.discount_start,
            sg.min_order,
            sg.is_available_for_credit,
            sg.is_available,
            sg.last_update_code,
            g.name,
            g.description,
            g.image_url,
            g.priority,
            g.commission,
            c.name AS category_name
        FROM supplier_goods sg
        INNER JOIN goods g ON g.id = sg.goods_id
        LEFT JOIN category c ON c.id = g.category_id
        WHERE sg.supplier_id = :supplierId AND sg.is_available = 1';

    if ($searchTerm !== null) {
        $goodsQuery .= ' AND (
            g.name LIKE :searchTerm
            OR g.description LIKE :searchTerm
            OR c.name LIKE :searchTerm
        )';
    }

    $goodsQuery .= ' ORDER BY g.priority DESC, sg.price ASC, sg.min_order ASC
        LIMIT :limitPlusOne OFFSET :offset';

    $goodsStmt = $db->prepare($goodsQuery);
    $goodsStmt->bindValue(':supplierId', $supplierId, PDO::PARAM_INT);
    if ($searchTerm !== null) {
        $goodsStmt->bindValue(':searchTerm', $searchTerm, PDO::PARAM_STR);
    }
    $goodsStmt->bindValue(':limitPlusOne', $limitPlusOne, PDO::PARAM_INT);
    $goodsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $goodsStmt->execute();
    $rows = $goodsStmt->fetchAll();

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }

    $goods = [];
    foreach ($rows as $row) {
        $goods[] = [
            'supplierGoodsId' => (int)$row['supplier_goods_id'],
            'goodsId' => (int)$row['goods_id'],
            'name' => $row['name'] ?? '',
            'description' => $row['description'] ?? '',
            'imageUrl' => $row['image_url'] ?? '',
            'categoryName' => $row['category_name'] ?? '',
            'commission' => isset($row['commission']) ? (float)$row['commission'] : 0.0,
            'price' => isset($row['price']) ? (float)$row['price'] : null,
            'discountPrice' => isset($row['discount_price']) ? (float)$row['discount_price'] : null,
            'discountStart' => isset($row['discount_start']) ? (int)$row['discount_start'] : null,
            'minOrder' => (int)$row['min_order'],
            'isAvailableForCredit' => (int)$row['is_available_for_credit'] === 1,
            'isAvailable' => (int)$row['is_available'] === 1,
            'priority' => isset($row['priority']) ? (int)$row['priority'] : 0,
            'lastUpdateCode' => (int)$row['last_update_code']
        ];
    }

    echo json_encode([
        'success' => true,
        'supplier' => [
            'shopId' => (int)$supplier['shop_id'],
            'shopName' => $supplier['shop_name'] ?? '',
            'shopType' => $supplier['shop_type'] ?? '',
            'phone' => $supplier['phone'] ?? ''
        ],
        'goods' => $goods,
        'count' => count($goods),
        'hasMore' => $hasMore,
        'nextOffset' => $offset + count($goods)
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error while fetching supplier goods.',
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
