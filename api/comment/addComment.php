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
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        INSERT INTO comments (customer_id, comment, star_value, goods_id)
        VALUES (:customerId, :comment, :starValue, :goodsId)
    ");
    $stmt->bindParam(':customerId', $customerId);
    $stmt->bindParam(':comment', $comment);
    $stmt->bindParam(':starValue', $starValue);
    $stmt->bindParam(':goodsId', $goodsId);
    $stmt->execute();

    $avgStmt = $conn->prepare("
        SELECT AVG(star_value) AS average_star
        FROM comments
        WHERE goods_id = :goodsId
    ");
    $avgStmt->bindParam(':goodsId', $goodsId);
    $avgStmt->execute();
    $avgResult = $avgStmt->fetch(PDO::FETCH_ASSOC);

    $averageStar = $avgResult && $avgResult['average_star'] !== null ? (float) $avgResult['average_star'] : 0.0;
    $normalizedStar = max(0, min(5, round($averageStar, 2)));

    $updateStmt = $conn->prepare("
        UPDATE goods
        SET star_value = :starValue
        WHERE id = :goodsId
    ");
    $updateStmt->bindValue(':starValue', $normalizedStar);
    $updateStmt->bindValue(':goodsId', $goodsId, PDO::PARAM_INT);
    $updateStmt->execute();

    $conn->commit();

    echo json_encode([
        'success' => 'Comment added successfully',
        'starValue' => $normalizedStar
    ]);
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>