<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/Database.php';

$database = new Database();
$conn = $database->connect();

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['goodsId']) || !isset($input['customerId']) || !isset($input['comment']) || !isset($input['starValue'])) {
    echo json_encode(['error' => 'goodsId, customerId, comment, and starValue are required']);
    exit;
}

$goodsId = $input['goodsId'];
$customerId = $input['customerId'];
$comment = $input['comment'];
$starValue = $input['starValue'];

try {
    $stmt = $conn->prepare("
        INSERT INTO comments (customer_id, comment, star_value, goods_id)
        VALUES (:customerId, :comment, :starValue, :goodsId)
    ");
    $stmt->bindParam(':customerId', $customerId);
    $stmt->bindParam(':comment', $comment);
    $stmt->bindParam(':starValue', $starValue);
    $stmt->bindParam(':goodsId', $goodsId);
    $stmt->execute();

    echo json_encode(['success' => 'Comment added successfully']);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>