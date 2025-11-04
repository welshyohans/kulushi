<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || !isset($payload['customerId'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'customerId is required in the JSON body.'
    ]);
    exit;
}

$customerId = (int)$payload['customerId'];
if ($customerId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'customerId must be a positive integer.'
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
            c.id,
            c.name,
            c.phone,
            c.shop_name,
            c.specific_address,
            c.location_description,
            c.delivery_time_info,
            a.city,
            a.sub_city
        FROM customer c
        LEFT JOIN address a ON a.id = c.address_id
        WHERE c.id = :customerId
        LIMIT 1'
    );
    $stmt->execute([':customerId' => $customerId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found.'
        ]);
        exit;
    }

    $addressStmt = $db->prepare(
        'SELECT
            ca.id,
            ca.address_name,
            ca.is_main_address,
            ca.priority,
            a.city,
            a.sub_city
        FROM customer_address ca
        INNER JOIN address a ON a.id = ca.address_id
        WHERE ca.customer_id = :customerId
        ORDER BY ca.is_main_address DESC, ca.priority DESC'
    );
    $addressStmt->execute([':customerId' => $customerId]);
    $addresses = $addressStmt->fetchAll();

    $profile = [
        'id' => (int)$customer['id'],
        'name' => $customer['name'] ?? '',
        'phone' => $customer['phone'] ?? '',
        'shopName' => $customer['shop_name'] ?? '',
        'specificAddress' => $customer['specific_address'] ?? '',
        'locationDescription' => $customer['location_description'] ?? '',
        'deliveryInfo' => $customer['delivery_time_info'] ?? '',
        'address' => [
            'city' => $customer['city'] ?? '',
            'subCity' => $customer['sub_city'] ?? ''
        ],
        'addresses' => array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'name' => $row['address_name'] ?? '',
                'isMain' => (int)$row['is_main_address'] === 1,
                'priority' => isset($row['priority']) ? (int)$row['priority'] : null,
                'city' => $row['city'] ?? '',
                'subCity' => $row['sub_city'] ?? ''
            ];
        }, $addresses)
    ];

    echo json_encode([
        'success' => true,
        'profile' => $profile
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error while fetching profile.',
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