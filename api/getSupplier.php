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
            s.shop_id,
            s.shop_name,
            s.shop_detail,
            s.shop_type,
            s.address_id,
            s.phone,
            s.priority,
            s.password,
            s.image,
            s.isVisible,
            s.last_update,
            s.last_update_code
        FROM supplier s
        ORDER BY s.priority DESC, s.shop_name ASC'
    );

    $suppliers = [];
    while ($row = $stmt->fetch()) {
        $suppliers[] = [
            'shopId' => (int)$row['shop_id'],
            'shopName' => $row['shop_name'] ?? '',
            'shopDetail' => $row['shop_detail'] ?? '',
            'shopType' => $row['shop_type'] ?? '',
            'addressId' => $row['address_id'] !== null ? (int)$row['address_id'] : null,
            'phone' => $row['phone'] ?? '',
            'priority' => $row['priority'] !== null ? (int)$row['priority'] : null,
            'password' => $row['password'] ?? '',
            'image' => $row['image'] ?? '',
            'isVisible' => $row['isVisible'] !== null ? (int)$row['isVisible'] : 0,
            'lastUpdate' => $row['last_update'] ?? '',
            'lastUpdateCode' => $row['last_update_code'] !== null ? (int)$row['last_update_code'] : null
        ];
    }

    echo json_encode([
        'success' => true,
        'suppliers' => $suppliers,
        'count' => count($suppliers)
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error while fetching suppliers.',
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