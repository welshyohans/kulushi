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

date_default_timezone_set('Africa/Addis_Ababa');

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
        $respond(500, ['success' => false, 'message' => 'Database connection failed.']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare(
            'SELECT id, name, channel, purpose, status, audience_type, segment, address_id,
                    min_recipients, max_recipients, title, scheduled_at, scheduled_tz, created_at, sent_at, status_note
             FROM message_campaigns
             ORDER BY created_at DESC
             LIMIT 200'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $respond(200, ['success' => true, 'count' => count($rows), 'items' => $rows]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '', true);
    if (!is_array($payload)) {
        $respond(400, ['success' => false, 'message' => 'Invalid JSON payload.']);
    }

    $name = isset($payload['name']) ? trim((string)$payload['name']) : '';
    $channel = isset($payload['channel']) ? trim((string)$payload['channel']) : '';
    $purpose = isset($payload['purpose']) ? trim((string)$payload['purpose']) : '';
    $title = isset($payload['title']) ? trim((string)$payload['title']) : null;
    $body = isset($payload['body']) ? trim((string)$payload['body']) : '';
    $segment = isset($payload['segment']) ? trim((string)$payload['segment']) : null;
    $addressId = isset($payload['addressId']) ? (int)$payload['addressId'] : null;
    $scheduledAtRaw = isset($payload['scheduledAt']) ? trim((string)$payload['scheduledAt']) : '';
    $minRecipients = isset($payload['minRecipients']) ? (int)$payload['minRecipients'] : 0;
    $maxRecipients = isset($payload['maxRecipients']) ? (int)$payload['maxRecipients'] : 0;
    $createdBy = isset($payload['createdBy']) ? (int)$payload['createdBy'] : null;
    $dataPayload = isset($payload['dataPayload']) && is_array($payload['dataPayload'])
        ? json_encode($payload['dataPayload'])
        : null;

    if ($name === '' || $channel === '' || $purpose === '' || $body === '') {
        $respond(422, ['success' => false, 'message' => 'name, channel, purpose, and body are required.']);
    }

    $allowedChannels = ['sms', 'fcm', 'both'];
    if (!in_array($channel, $allowedChannels, true)) {
        $respond(422, ['success' => false, 'message' => 'channel must be sms, fcm, or both.']);
    }

    $allowedPurposes = ['promotional', 'delivering', 'ordering', 'price_change', 'new_product'];
    if (!in_array($purpose, $allowedPurposes, true)) {
        $respond(422, ['success' => false, 'message' => 'Invalid purpose.']);
    }

    if ($purpose === 'promotional' && $minRecipients < 100) {
        $minRecipients = 100;
    }

    $audienceType = 'all';
    if ($segment !== null && $segment !== '') {
        $audienceType = 'segment';
    } elseif (!empty($addressId)) {
        $audienceType = 'address';
    }

    $scheduledAt = null;
    $status = 'draft';
    if ($scheduledAtRaw !== '') {
        $scheduledAt = DateTime::createFromFormat('Y-m-d H:i', $scheduledAtRaw)
            ?: DateTime::createFromFormat('Y-m-d H:i:s', $scheduledAtRaw);
        if (!$scheduledAt) {
            $respond(422, ['success' => false, 'message' => 'scheduledAt must be YYYY-MM-DD HH:MM.']);
        }
        $status = 'scheduled';
    }

    $stmt = $db->prepare(
        'INSERT INTO message_campaigns (
            name, channel, purpose, status, audience_type, segment, address_id,
            min_recipients, max_recipients, title, body, data_payload_json,
            scheduled_at, scheduled_tz, created_by
         ) VALUES (
            :name, :channel, :purpose, :status, :audience_type, :segment, :address_id,
            :min_recipients, :max_recipients, :title, :body, :data_payload_json,
            :scheduled_at, :scheduled_tz, :created_by
         )'
    );
    $stmt->execute([
        ':name' => $name,
        ':channel' => $channel,
        ':purpose' => $purpose,
        ':status' => $status,
        ':audience_type' => $audienceType,
        ':segment' => $segment !== '' ? $segment : null,
        ':address_id' => $addressId ?: null,
        ':min_recipients' => $minRecipients,
        ':max_recipients' => $maxRecipients,
        ':title' => $title === '' ? null : $title,
        ':body' => $body,
        ':data_payload_json' => $dataPayload,
        ':scheduled_at' => $scheduledAt ? $scheduledAt->format('Y-m-d H:i:s') : null,
        ':scheduled_tz' => 'Africa/Addis_Ababa',
        ':created_by' => $createdBy
    ]);

    $respond(201, ['success' => true, 'message' => 'Campaign saved.']);
} catch (PDOException $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Database error.',
        'error' => $exception->getMessage()
    ]);
} catch (Throwable $throwable) {
    $respond(500, [
        'success' => false,
        'message' => 'Unexpected server error.',
        'error' => $throwable->getMessage()
    ]);
}
