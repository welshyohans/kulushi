<?php
header('Content-Type: application/json');

include_once '../../../config/Database.php';
include_once '../../../model/Settings.php';
include_once '../../../load_env.php';
loadEnv('../../../.env');

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

// add helper to detect column existence
function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1');
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (bool)$stmt->fetchColumn();
}

function mapAddressRow(array $row): array {
    return [
        'addressId' => (int)($row['id'] ?? 0),
        'city' => (string)($row['city'] ?? ''),
        'subCity' => (string)($row['sub_city'] ?? ''),
        'hasSupplier' => (int)($row['has_supplier'] ?? 0),
        'lastUpdateCode' => (int)($row['last_update_code'] ?? 0),
    ];
}

function mapCategoryRow(array $row): array {
    return [
        'categoryId' => (int)($row['id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'imageUrl' => (string)($row['image_url'] ?? ''),
        'isAvailable' => (int)($row['is_available'] ?? 0),
        'lastUpdateCode' => (int)($row['last_update_code'] ?? 0),
        'priority' => (int)($row['priority'] ?? 0),
    ];
}

function mapGoodsRow(array $row): array {
    return [
        'goodsId' => (int)($row['id'] ?? 0),
        'categoryId' => (int)($row['category_id'] ?? 0),
        'brandId' => (int)($row['brand_id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'priority' => (int)($row['priority'] ?? 0),
        'showInHome' => (int)($row['show_in_home'] ?? 0),
        'imageUrl' => (string)($row['image_url'] ?? ''),
        'lastUpdateCode' => (int)($row['last_update_code'] ?? 0),
        'lastUpdate' => (string)($row['last_update'] ?? ''),
        'starValue' => (double)($row['star_value'] ?? 0),
        'tiktokUrl' => (string)($row['tiktok_url'] ?? ''),
        'commission' => (int)($row['commission'] ?? 0),
    ];
}

function mapCustomerAddressRow(array $row): array {
    return [
        'customerAddressId' => (int)($row['id'] ?? 0),
        'customerId' => (int)($row['customer_id'] ?? 0),
        'addressId' => (int)($row['address_id'] ?? 0),
        'addressName' => (string)($row['address_name'] ?? ''),
        'isMainAddress' => (int)($row['is_main_address'] ?? 0),
        'priority' => (int)($row['priority'] ?? 0),
        'lastUpdateTime' => (string)($row['last_update_time'] ?? ''),
    ];
}

function mapSupplierRow(array $row): array {
    return [
        'shopId' => (int)($row['shop_id'] ?? 0),
        'shopName' => (string)($row['shop_name'] ?? ''),
        'shopDetail' => (string)($row['shop_detail'] ?? ''),
        'shopType' => (string)($row['shop_type'] ?? ''),
        'addressId' => (int)($row['address_id'] ?? 0),
        'phone' => (int)($row['phone'] ?? 0),
        'priority' => (int)($row['priority'] ?? 0),
        'password' => (int)($row['password'] ?? 0),
        'image' => (string)($row['image'] ?? ''),
        'isVisible' => (int)($row['isVisible'] ?? 0),
        'lastUpdate' => (string)($row['last_update'] ?? ''),
        'lastUpdateCode' => (int)($row['last_update_code'] ?? 0),
    ];
}

function mapSupplierGoodsRow(array $row): array {
    $lastUpdateCode = (int)($row['last_update_code'] ?? 0);
    return [
        'id' => (int)($row['id'] ?? 0),
        'supplierId' => (int)($row['supplier_id'] ?? 0),
        'goodsId' => (int)($row['goods_id'] ?? 0),
        'price' => (float)($row['price'] ?? 0),
        'discountStart' => (int)($row['discount_start'] ?? 0),
        'discountPrice' => (float)($row['discount_price'] ?? 0),
        'minOrder' => (int)($row['min_order'] ?? 0),
        'isAvailableForCredit' => (int)($row['is_available_for_credit'] ?? 0),
        'isAvailable' => (int)($row['is_available'] ?? 0),
        'lastUpdateCode' => $lastUpdateCode,
        'lastUpdatePrice' => (int)($row['last_update_price'] ?? $lastUpdateCode),
        'lastUpdateAvailable' => (int)($row['last_update_available'] ?? $lastUpdateCode),
    ];
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

$customerId = (int)$data['customerId'];
$requestedCode = array_key_exists('lastUpdateCode', $data) ? (int)$data['lastUpdateCode'] : 0;
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

    // decide which column to update/use for FCM token: prefer fcm_code if present, otherwise use firebase_code
    $fcmColumn = columnExists($db, 'customer', 'fcm_code') ? 'fcm_code' : 'firebase_code';

    if ($fcmCode !== null && $fcmCode !== '') {
        $currentToken = isset($customer[$fcmColumn]) ? (string)$customer[$fcmColumn] : '';
        if ($currentToken !== $fcmCode) {
            $upd = $db->prepare("UPDATE customer SET {$fcmColumn} = :code WHERE id = :id");
            $upd->execute([':code' => $fcmCode, ':id' => $customerId]);
            $customer[$fcmColumn] = $fcmCode;
        }
    }

    $cityParts = [];
    if (!empty($customer['city'])) {
        $cityParts[] = $customer['city'];
    }
    if (!empty($customer['sub_city'])) {
        $cityParts[] = $customer['sub_city'];
    }
    $addressName = implode(', ', $cityParts);

    $addressParts = [];
    if (!empty($customer['specific_address'])) {
        $addressParts[] = $customer['specific_address'];
    }
    if ($addressName !== '') {
        $addressParts[] = $addressName;
    }
    $address = implode(', ', $addressParts);
    if ($address === '') {
        $address = $addressName;
    }

    $currentLastCode = (int)$settingsModel->getValue('last_update_code', 0);
    $expireTime = (int)$settingsModel->getValue('expire_time', 0);
    $permittedCredit = array_key_exists('permitted_credit', $customer) ? (int)$customer['permitted_credit'] : 0;

    // fetch oldest unpaid due date from credit table (paid < total)
    $dueDate = '';
    $dueStmt = $db->prepare('SELECT due_date FROM credit WHERE customer_id = :customer_id AND paid < total ORDER BY due_date ASC LIMIT 1');
    $dueStmt->execute([':customer_id' => $customerId]);
    $dueVal = $dueStmt->fetchColumn();
    if ($dueVal !== false && $dueVal !== null) {
        $dueDate = (string)$dueVal;
    }

    $segment = isset($customer['segment']) ? (string)$customer['segment'] : '';
    $showCampaign = in_array($segment, ['loyal', 'vip'], true) ? 1 : 0;

    $settingsSnapshot = [
        'customerId' => $customerId,
        'shopName' => (string)$customer['shop_name'],
        'userName' => (string)$customer['name'],
        'phoneNumber' => (string)$customer['phone'],
        'address' => $address,
        'totalCredit' => (int)$customer['total_credit'],
        'unpaidAmount' => (int)$customer['total_unpaid'],
        'expireTime' => $expireTime,
        'permittedCredit' => $permittedCredit,
        'userType' => (string)($customer['user_type'] ?? ''),
        'lastUpdateCode' => (string)$currentLastCode,
        'showCampaign' => $showCampaign,
        // due date from credit table (oldest where paid < total)
        'dueDate' => $dueDate,
        // use the chosen column value here
        'fcmCode' => (string)($customer[$fcmColumn] ?? ''),
        'geminiApiKey'=>getenv('openRouterApiKey') ?: '',
        // Legacy keys kept for backward compatibility
        'customerName' => (string)$customer['name'],
        'customerShopName' => (string)$customer['shop_name'],
        'addressName' => $addressName,
        'deliveryTimeInfo' => (string)($customer['delivery_time_info'] ?? ''),
        'requestedLastUpdateCode' => $requestedCode
    ];

    $params = [':code' => $requestedCode];

    $addressList = array_map('mapAddressRow', fetchRows(
        $db,
        'SELECT * FROM address WHERE last_update_code > :code ORDER BY last_update_code ASC',
        $params
    ));

    $customerAddressRows = fetchRows(
        $db,
        'SELECT * FROM customer_address WHERE customer_id = :customer_id',
        [':customer_id' => $customerId]
    );
    $customerAddressList = array_map('mapCustomerAddressRow', $customerAddressRows);

    $allowedAddressIds = [];
    foreach ($customerAddressRows as $row) {
        $addrId = isset($row['address_id']) ? (int)$row['address_id'] : 0;
        if ($addrId > 0) {
            $allowedAddressIds[$addrId] = true;
        }
    }
    $allowedAddressIds = array_values(array_map('intval', array_keys($allowedAddressIds)));

    $supplierRows = [];
    $allowedSupplierIds = [];

    if (!empty($allowedAddressIds)) {
        $addressPlaceholders = [];
        $addressParams = [];
        foreach ($allowedAddressIds as $idx => $addrId) {
            $param = ":addr{$idx}";
            $addressPlaceholders[] = $param;
            $addressParams[$param] = $addrId;
        }
        $addressInClause = implode(',', $addressPlaceholders);

        $supplierParams = array_merge([':code' => $requestedCode], $addressParams);
        $supplierRows = fetchRows(
            $db,
            'SELECT * FROM supplier WHERE last_update_code > :code AND address_id IN (' . $addressInClause . ') ORDER BY last_update_code ASC',
            $supplierParams
        );

        $supplierIdRows = fetchRows(
            $db,
            'SELECT shop_id FROM supplier WHERE address_id IN (' . $addressInClause . ')',
            $addressParams
        );
        $supplierIdSet = [];
        foreach ($supplierIdRows as $row) {
            $supplierId = isset($row['shop_id']) ? (int)$row['shop_id'] : 0;
            if ($supplierId > 0) {
                $supplierIdSet[$supplierId] = true;
            }
        }
        $allowedSupplierIds = array_values(array_map('intval', array_keys($supplierIdSet)));
    }

    $categoriesList = array_map('mapCategoryRow', fetchRows(
        $db,
        'SELECT * FROM category WHERE last_update_code > :code ORDER BY last_update_code ASC',
        $params
    ));
    $goodsList = array_map('mapGoodsRow', fetchRows(
        $db,
        'SELECT * FROM goods WHERE last_update_code > :code ORDER BY last_update_code ASC',
        $params
    ));
    $suppliersList = array_map('mapSupplierRow', $supplierRows);

    $supplierGoodsList = [];
    if (!empty($allowedSupplierIds)) {
        $supplierPlaceholders = [];
        $supplierGoodsParams = [':code' => $requestedCode];
        foreach ($allowedSupplierIds as $idx => $supplierId) {
            $param = ":sup{$idx}";
            $supplierPlaceholders[] = $param;
            $supplierGoodsParams[$param] = $supplierId;
        }
        $supplierGoodsSql = 'SELECT * FROM supplier_goods WHERE last_update_code > :code AND supplier_id IN (' . implode(',', $supplierPlaceholders) . ') ORDER BY last_update_code ASC';
        $supplierGoodsList = array_map('mapSupplierGoodsRow', fetchRows(
            $db,
            $supplierGoodsSql,
            $supplierGoodsParams
        ));
    }

    $payload = [
        'success' => true,
        'message' => 'Data sync payload success',
        'settings' => $settingsSnapshot,
        'categoriesList' => $categoriesList,
        'goodsList' => $goodsList,
        'addressList' => $addressList,
        'customerAddressList' => $customerAddressList,
        'suppliersList' => $suppliersList,
        'supplierGoodsList' => $supplierGoodsList,
        // Legacy keys kept alongside new Android-aligned keys
        //'listOfCategory' => $categoriesList,
        //'listOfGoods' => $goodsList,
        //'listOfSuppliers' => $suppliersList,
        //'listOfSupplierGoods' => $supplierGoodsList
    ];

    $response(200, $payload);
} catch (PDOException $e) {
    $response(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    $response(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}
