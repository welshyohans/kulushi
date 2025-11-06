<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'];

require_once __DIR__ . '/../config/Database.php';

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
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Database connection error.',
        'error' => $exception->getMessage()
    ]);
}

$loadCustomer = static function (PDO $db, ?int $customerId, ?string $phone): ?array {
    if ($customerId !== null) {
        $stmt = $db->prepare('SELECT id, name, phone, shop_name, permitted_credit, total_credit, total_unpaid FROM customer WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $customerId]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }
    }

    if ($phone !== null) {
        $stmt = $db->prepare('SELECT id, name, phone, shop_name, permitted_credit, total_credit, total_unpaid FROM customer WHERE phone = :phone LIMIT 1');
        $stmt->execute([':phone' => $phone]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }
    }

    return null;
};

switch ($method) {
    case 'GET':
        $customerId = null;
        $phone = null;

        $customerIdInput = filter_input(INPUT_GET, 'customerId', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($customerIdInput !== false && $customerIdInput !== null) {
            $customerId = $customerIdInput;
        }

        $phoneInput = filter_input(INPUT_GET, 'phone', FILTER_UNSAFE_RAW);
        if ($phoneInput !== null) {
            $trimmed = trim((string)$phoneInput);
            if ($trimmed !== '') {
                $phone = $trimmed;
            }
        }

        if ($customerId === null && $phone === null) {
            $respond(400, [
                'success' => false,
                'message' => 'Provide either customerId or phone.'
            ]);
        }

        $customer = $loadCustomer($db, $customerId, $phone);
        if ($customer === null) {
            $respond(404, [
                'success' => false,
                'message' => 'Customer not found.',
                'customerId' => $customerId,
                'phone' => $phone
            ]);
        }

        $respond(200, [
            'success' => true,
            'customer' => [
                'id' => (int)$customer['id'],
                'name' => $customer['name'] ?? '',
                'phone' => $customer['phone'] ?? '',
                'shopName' => $customer['shop_name'] ?? '',
                'permittedCredit' => (int)$customer['permitted_credit'],
                'totalCredit' => isset($customer['total_credit']) ? (int)$customer['total_credit'] : null,
                'totalUnpaid' => isset($customer['total_unpaid']) ? (int)$customer['total_unpaid'] : null
            ]
        ]);
        break;

    case 'POST':
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) {
            $respond(400, ['success' => false, 'message' => 'Unable to read request body.']);
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $respond(400, ['success' => false, 'message' => 'Invalid JSON payload.']);
        }

        $customerId = null;
        $phone = null;

        if (array_key_exists('customerId', $data)) {
            $customerIdValidated = filter_var($data['customerId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($customerIdValidated === false) {
                $respond(422, ['success' => false, 'message' => 'customerId must be a positive integer.']);
            }
            $customerId = $customerIdValidated;
        }

        if (array_key_exists('phone', $data)) {
            $phoneValue = trim((string)$data['phone']);
            if ($phoneValue !== '') {
                $phone = $phoneValue;
            }
        }

        if ($customerId === null && $phone === null) {
            $respond(400, ['success' => false, 'message' => 'Provide customerId or phone in the payload.']);
        }

        if (!array_key_exists('permittedCredit', $data)) {
            $respond(400, ['success' => false, 'message' => 'Missing field: permittedCredit']);
        }

        $permittedCredit = filter_var($data['permittedCredit'], FILTER_VALIDATE_INT);
        if ($permittedCredit === false || $permittedCredit < 0) {
            $respond(422, ['success' => false, 'message' => 'permittedCredit must be a non-negative integer.']);
        }

        $customer = $loadCustomer($db, $customerId, $phone);
        if ($customer === null) {
            $respond(404, [
                'success' => false,
                'message' => 'Customer not found.',
                'customerId' => $customerId,
                'phone' => $phone
            ]);
        }

        $currentCredit = (int)$customer['permitted_credit'];
        if ($currentCredit === $permittedCredit) {
            $respond(200, [
                'success' => true,
                'message' => 'Permitted credit unchanged.',
                'customerId' => (int)$customer['id'],
                'permittedCredit' => $permittedCredit
            ]);
        }

        try {
            $db->beginTransaction();

            $updateStmt = $db->prepare('UPDATE customer SET permitted_credit = :credit WHERE id = :id');
            $updateStmt->execute([
                ':credit' => $permittedCredit,
                ':id' => $customer['id']
            ]);

            if ($updateStmt->rowCount() === 0) {
                $db->rollBack();
                $respond(409, [
                    'success' => false,
                    'message' => 'Permitted credit update failed.',
                    'customerId' => (int)$customer['id']
                ]);
            }

            $db->commit();

            $respond(200, [
                'success' => true,
                'message' => 'Permitted credit updated successfully.',
                'customerId' => (int)$customer['id'],
                'previousCredit' => $currentCredit,
                'newCredit' => $permittedCredit
            ]);
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $respond(500, [
                'success' => false,
                'message' => 'Database error while updating permitted credit.',
                'error' => $exception->getMessage()
            ]);
        } catch (Throwable $throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $respond(500, [
                'success' => false,
                'message' => 'Unexpected server error.',
                'error' => $throwable->getMessage()
            ]);
        }

        break;

    default:
        $respond(405, [
            'success' => false,
            'message' => 'Method not allowed. Use GET or POST.'
        ]);
}