<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
    exit;
}

try {
    $database = new Database();
    $db = $database->connect();

    $stmt = $db->prepare(
        'SELECT 
            id,
            name,
            phone,
            shop_name,
            created_at,
            updated_at
         FROM customer
         ORDER BY name ASC, id ASC'
    );
    $stmt->execute();

    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'count' => count($customers),
        'customers' => $customers
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch customers.',
        'error' => $exception->getMessage()
    ]);
}