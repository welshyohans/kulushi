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

require_once __DIR__ . '/../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $stmt = $db->query(
        'SELECT
            g.id,
            g.name,
            g.description,
            g.priority,
            g.show_in_home,
            g.last_update_code,
            g.last_update,
            g.image_url,
            g.star_value,
            g.tiktok_url,
            g.commission,
            c.name AS category_name,
            b.name AS brand_name
        FROM goods g
        LEFT JOIN category c ON c.id = g.category_id
        LEFT JOIN brand b ON b.id = g.brand_id
        ORDER BY g.priority DESC, g.last_update DESC, g.id DESC'
    );

    $goods = [];
    while ($row = $stmt->fetch()) {
        $goods[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'] ?? '',
            'description' => $row['description'] ?? '',
            'priority' => $row['priority'] !== null ? (int)$row['priority'] : null,
            'showInHome' => isset($row['show_in_home']) ? (bool)$row['show_in_home'] : false,
            'lastUpdateCode' => $row['last_update_code'] !== null ? (int)$row['last_update_code'] : null,
            'lastUpdate' => $row['last_update'] ?? '',
            'imageUrl' => $row['image_url'] ?? '',
            'categoryName' => $row['category_name'] ?? '',
            'brandName' => $row['brand_name'] ?? '',
            'starValue' => $row['star_value'] ?? '',
            'tiktokUrl' => $row['tiktok_url'] ?? '',
            'commission' => $row['commission'] !== null ? (int)$row['commission'] : null
        ];
    }

    echo json_encode([
        'success' => true,
        'goods' => $goods,
        'count' => count($goods)
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