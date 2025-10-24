<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/Database.php';

$database = new Database();
$conn = $database->connect();

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['goodsId'])) {
    echo json_encode(['error' => 'goodsId is required']);
    exit;
}

$goodsId = $input['goodsId'];

try {
    $stmt = $conn->prepare("
        SELECT c.name AS customerName, ca.address_name AS addressName, cm.comment, cm.star_value AS StarValue
        FROM comments cm
        LEFT JOIN customer c ON cm.customer_id = c.id
        LEFT JOIN customer_address ca ON ca.id = cm.customer_address_id
        WHERE cm.goods_id = :goodsId
    ");
    $stmt->bindParam(':goodsId', $goodsId);
    $stmt->execute();

    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($comments);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>