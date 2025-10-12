<?php
header('Content-Type: application/json');

include_once '../../../config/Database.php';
include_once '../../../model/Settings.php';

$response = function (int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
};

function fetchRows(PDO $db, string $sql, array $params = []): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return normalizeRows($stmt->fetchAll());
}

function normalizeRows(array $rows): array {
    return array_map('normalizeRow', $rows);
}

function normalizeRow(array $row): array {
    foreach ($row as $key => $value) {
        if (is_string($value) && is_numeric($value)) {
            if (preg_match('/^-?\d+$/', $value)) {
                $row[$key] = (int)$value;
            } elseif (preg_match('/^-?\d+\.\d+$/', $value)) {
                $row[$key] = (float)$value;
            }
        }
    }
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response(405, ['success' => false, 'message' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $response(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

if (!array_key_exists('customerId', $data)) {
    $response(400, ['success' => false, 'message' => 'Missing field: customerId']);
}
if (!array_key_exists('lastUpdateCode', $data)) {
    $response(400, ['success' => false, 'message' => 'Missing field: lastUpdateCode']);
}

$customerId = (int)$data['customerId'];
$clientLastCode = (int)$data['lastUpdateCode'];
$fcmCode = array_key_exists('fcmCode', $data) ? trim((string)$data['fcmCode']) : null;

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $settingsModel = new Settings($db);

    $customerStmt = $db->prepare('SELECT c.*, a.city, a.sub_city FROM customer c LEFT JOIN address a ON c.address_id = a.id WHERE c.id = :id LIMIT 1');
    $customerStmt->execute([':id' => $customerId]);
    $customer = $customerStmt->fetch();
    if (!$customer) {
        $response(404, ['success' => false, 'message' => 'Customer not found', 'customerId' => $customerId]);
    }

    if ($fcmCode !== null && $fcmCode !== '' && $customer['firebase_code'] !== $fcmCode) {
        $upd = $db->prepare('UPDATE customer SET firebase_code = :code WHERE id = :id');
        $upd->execute([':code' => $fcmCode, ':id' => $customerId]);
        $customer['firebase_code'] = $fcmCode;
    }

    $addressParts = [];
    if (!empty($customer['city'])) {
        $addressParts[] = $customer['city'];
    }
    if (!empty($customer['sub_city'])) {
        $addressParts[] = $customer['sub_city'];
    }
    $addressName = implode(', ', $addressParts);

    $settingsSnapshot = [
        'customerId' => $customerId,
        'customerName' => $customer['name'],
        'phoneNumber' => $customer['phone'],
        'customerShopName' => $customer['shop_name'],
        'addressName' => $addressName,
        'totalCredit' => (int)$customer['total_credit'],
        'expireTime' => (int)$settingsModel->getValue('expire_time', 0),
        'lastUpdateCode' => (int)$settingsModel->getValue('last_update_code', 0),
        'requestedLastUpdateCode' => $clientLastCode
    ];

    $params = [':code' => $clientLastCode];

    $updatedCategory = fetchRows($db, 'SELECT * FROM category WHERE last_update_code > :code ORDER BY last_update_code ASC', $params);
    $updatedCategoryAvailability = fetchRows($db, 'SELECT * FROM category WHERE last_update_availability > :code ORDER BY last_update_availability ASC', $params);

    $updatedGoods = fetchRows($db, 'SELECT * FROM goods WHERE last_update_code > :code ORDER BY last_update_code ASC', $params);
    $updatedGoodsPriority = fetchRows($db, 'SELECT * FROM goods WHERE last_update_priority > :code ORDER BY last_update_priority ASC', $params);

    $updatedSuppliers = fetchRows($db, 'SELECT * FROM supplier WHERE last_update_code > :code ORDER BY last_update_code ASC', $params);

    $updatedSupplierGoods = fetchRows($db, 'SELECT * FROM supplier_goods WHERE last_update_code > :code ORDER BY last_update_code ASC', $params);
    $updatedSupplierGoodsAvailability = fetchRows($db, 'SELECT * FROM supplier_goods WHERE last_update_available > :code ORDER BY last_update_available ASC', $params);
    $updatedSupplierGoodsPrice = fetchRows($db, 'SELECT * FROM supplier_goods WHERE last_update_price > :code ORDER BY last_update_price ASC', $params);

    $payload = [
        'success' => true,
        'message' => 'Incremental update payload',
        'settings' => $settingsSnapshot,
        'listOfUpdatedCategory' => $updatedCategory,
        'listOfUpdatedCategoryAvailablity' => $updatedCategoryAvailability,
        'listOfUpdatedCategoryPriority' => [],
        'listOfUpdatedGoods' => $updatedGoods,
        'listOfUpdatedGoodsPriority' => $updatedGoodsPriority,
        'listOfUpdatedSuppliers' => $updatedSuppliers,
        'listOfUpdatedSupplierPriority' => [],
        'listOfUpdatedSupplierGoods' => $updatedSupplierGoods,
        'listOfUpdatedSupplierGoodsAvailablity' => $updatedSupplierGoodsAvailability,
        'listOfUpdatedSupplierGoodsPrice' => $updatedSupplierGoodsPrice,
        'listOfUpdatedSupplierGoodsAvailablities' => $updatedSupplierGoodsAvailability
    ];

    $response(200, $payload);
} catch (PDOException $e) {
    $response(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    $response(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}
