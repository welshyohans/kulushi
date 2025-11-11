<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET or POST.'
    ]);
    exit;
}

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

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
        'SELECT id, name FROM customer ORDER BY name ASC, id ASC'
    );
    $stmt->execute();
    $rawCustomers = $stmt->fetchAll();

    $customers = [];
    foreach ($rawCustomers as $entry) {
        $customers[] = [
            'customerId' => (int)$entry['id'],
            'customerName' => (string)$entry['name']
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
}
