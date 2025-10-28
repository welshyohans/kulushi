<?php
header('Content-Type: application/json');


include_once '../../config/Database.php';
include_once '../../model/SMS.php';

$respond = function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    $respond(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

if (!array_key_exists('phone', $body) || trim((string)$body['phone']) === '') {
    $respond(400, ['success' => false, 'message' => 'Missing or empty field: phone']);
}

$standardizePhone = function (string $input) use ($respond): string {
    $digits = preg_replace('/\D+/', '', $input);
    if ($digits === '') {
        $respond(422, ['success' => false, 'message' => 'phone is invalid']);
    }

    if (strlen($digits) === 9) {
        return '0' . $digits;
    }

    if (strlen($digits) === 10 && $digits[0] === '0') {
        return $digits;
    }

    if (strncmp($digits, '251', 3) === 0 && strlen($digits) >= 12) {
        return '0' . substr($digits, -9);
    }

    $respond(422, ['success' => false, 'message' => 'phone format is not supported']);
};

$phone = $standardizePhone(trim((string)$body['phone']));

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $stmt = $db->prepare('SELECT password FROM customer WHERE phone = :phone LIMIT 1');
    $stmt->execute([':phone' => $phone]);
    $row = $stmt->fetch();

    if (!$row) {
        $respond(404, ['success' => false, 'message' => 'Customer not found']);
    }

    $password = (string)$row['password'];

    $sms = new SMS();
    $nationalPhone = substr($phone, -9);
    $recipient = '+251' . $nationalPhone;
    $message = "Your Merkato Pro password is {$password}.";
    $sms->sendSms($recipient, $message);

    $respond(200, [
        'success' => true,
        'message' => 'Password sent via SMS'
    ]);
} catch (PDOException $e) {
    $respond(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    $respond(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}