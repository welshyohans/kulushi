<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $respond(405, [
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
}

require_once __DIR__ . '/../../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        $respond(500, [
            'success' => false,
            'message' => 'Unable to establish database connection.'
        ]);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $stmt = $db->prepare(
        'SELECT
            c.id,
            c.name,
            c.shop_name,
            c.latitude,
            c.longitude,
            c.address_id,
            c.total_credit,
            c.total_unpaid,
            c.location_description,
            r.name AS registered_by_name
        FROM customer c
        LEFT JOIN customer r ON r.id = c.registered_by
        ORDER BY c.name ASC, c.id ASC'
    );
    $stmt->execute();

    $rawCustomers = $stmt->fetchAll();
    $customers = [];

    foreach ($rawCustomers as $row) {
        $shop = isset($row['shop_name']) ? trim((string)$row['shop_name']) : '';
        if ($shop === '') {
            $shop = null;
        }

        $locationDescription = isset($row['location_description']) ? trim((string)$row['location_description']) : '';
        if ($locationDescription === '') {
            $locationDescription = null;
        }

        $registeredByName = isset($row['registered_by_name']) ? trim((string)$row['registered_by_name']) : '';
        if ($registeredByName === '') {
            $registeredByName = null;
        }

        $customers[] = [
            'customerId' => (int)$row['id'],
            'customerName' => (string)$row['name'],
            'shop' => $shop,
            'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'addressId' => isset($row['address_id']) ? (int)$row['address_id'] : null,
            'totalCredit' => $row['total_credit'] !== null ? (float)$row['total_credit'] : null,
            'registeredBy' => $registeredByName,
            'location_description' => $locationDescription,
            'totalUnpaid' => $row['total_unpaid'] !== null ? (float)$row['total_unpaid'] : null
        ];
    }

    $respond(200, [
        'success' => true,
        'message' => null,
        'customers' => $customers
    ]);
} catch (PDOException $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Failed to fetch customers.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}
