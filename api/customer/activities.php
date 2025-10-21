<?php
header('Content-Type: application/json');

require_once '../../config/Database.php';

$respond = function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $respond(400, ['success' => false, 'message' => 'Unable to read request body']);
}

try {
    $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
} catch (JsonException $e) {
    $respond(400, ['success' => false, 'message' => 'Invalid JSON body', 'error' => $e->getMessage()]);
}

if (!is_array($data)) {
    $respond(400, ['success' => false, 'message' => 'Invalid request payload']);
}

if (!array_key_exists('customer_id', $data)) {
    $respond(400, ['success' => false, 'message' => 'Missing field: customer_id']);
}

$customerId = (int)$data['customer_id'];
if ($customerId <= 0) {
    $respond(422, ['success' => false, 'message' => 'customer_id must be a positive integer']);
}

$ensureArray = function (array $src, string $key) use ($respond): array {
    if (!array_key_exists($key, $src)) {
        $respond(400, ['success' => false, 'message' => "Missing field: {$key}"]);
    }
    if (!is_array($src[$key])) {
        $respond(422, ['success' => false, 'message' => "{$key} must be an array"]);
    }
    return $src[$key];
};

$truncate = function (?string $value, int $length) {
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }
    return strlen($value) > $length ? substr($value, 0, $length) : $value;
};

$requireBigint = function ($value, string $field) use ($respond): string {
    if (is_int($value) || is_float($value)) {
        $value = (string)(int)$value;
    } elseif (is_string($value)) {
        $value = trim($value);
    } else {
        $respond(422, ['success' => false, 'message' => "{$field} must be a numeric value"]);
    }

    if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
        $respond(422, ['success' => false, 'message' => "{$field} must be a numeric string"]);
    }

    return $value;
};

$requireUnsignedBigint = function ($value, string $field) use ($requireBigint, $respond): string {
    $numeric = $requireBigint($value, $field);
    if (str_starts_with($numeric, '-')) {
        $respond(422, ['success' => false, 'message' => "{$field} must be zero or positive"]);
    }
    return $numeric;
};

$toDateTime = function (string $epochValue, string $field) use ($respond): string {
    $normalized = ltrim($epochValue, '+');
    if ($normalized === '' || !preg_match('/^\d+$/', $normalized)) {
        $respond(422, ['success' => false, 'message' => "{$field} must be a non-negative integer epoch value"]);
    }

    $secondsString = strlen($normalized) > 10 ? substr($normalized, 0, -3) : $normalized;
    if ($secondsString === '') {
        $secondsString = '0';
    }
    $seconds = (int)$secondsString;

    try {
        $dt = (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));
    } catch (Exception $e) {
        $respond(422, ['success' => false, 'message' => "{$field} could not be converted to datetime", 'error' => $e->getMessage()]);
    }

    return $dt->format('Y-m-d H:i:s');
};

$contactsInput = $ensureArray($data, 'contacts');
$smsInput = $ensureArray($data, 'sms');
$activitiesInput = $ensureArray($data, 'activities');

$contactsPayload = [];
foreach ($contactsInput as $index => $contact) {
    if (!is_array($contact)) {
        $respond(422, ['success' => false, 'message' => "contacts[{$index}] must be an object"]);
    }
    foreach (['contact_id', 'name', 'phone', 'normalized_phone', 'created_at'] as $requiredField) {
        if (!array_key_exists($requiredField, $contact)) {
            $respond(422, ['success' => false, 'message' => "contacts[{$index}].{$requiredField} is required"]);
        }
    }

    $contactId = $requireUnsignedBigint($contact['contact_id'], "contacts[{$index}].contact_id");
    $createdAtMs = $requireUnsignedBigint($contact['created_at'], "contacts[{$index}].created_at");
    $name = $truncate((string)$contact['name'], 255);
    $phone = $truncate((string)$contact['phone'], 50);
    if ($phone === null) {
        $respond(422, ['success' => false, 'message' => "contacts[{$index}].phone cannot be empty"]);
    }
    $normalizedPhone = $truncate((string)$contact['normalized_phone'], 50);
    $additionalInfo = null;
    if (array_key_exists('additional_info', $contact)) {
        $additionalInfo = $truncate($contact['additional_info'] !== null ? (string)$contact['additional_info'] : null, 60000);
    }
    $contactsPayload[] = [
        'contact_id' => $contactId,
        'name' => $name,
        'phone' => $phone,
        'normalized_phone' => $normalizedPhone,
        'additional_info' => $additionalInfo,
        'created_at_ms' => $createdAtMs,
        'device_created_at' => $toDateTime($createdAtMs, "contacts[{$index}].created_at")
    ];
}

$smsPayload = [];
foreach ($smsInput as $index => $sms) {
    if (!is_array($sms)) {
        $respond(422, ['success' => false, 'message' => "sms[{$index}] must be an object"]);
    }
    foreach (['message_id', 'sender_phone', 'received_at', 'content'] as $requiredField) {
        if (!array_key_exists($requiredField, $sms)) {
            $respond(422, ['success' => false, 'message' => "sms[{$index}].{$requiredField} is required"]);
        }
    }

    $messageId = $requireUnsignedBigint($sms['message_id'], "sms[{$index}].message_id");
    $receivedAtMs = $requireUnsignedBigint($sms['received_at'], "sms[{$index}].received_at");
    $senderName = array_key_exists('sender_name', $sms) ? $truncate($sms['sender_name'] !== null ? (string)$sms['sender_name'] : null, 255) : null;
    $senderPhone = $truncate((string)$sms['sender_phone'], 50);
    if ($senderPhone === null) {
        $respond(422, ['success' => false, 'message' => "sms[{$index}].sender_phone cannot be empty"]);
    }
    $smsPayload[] = [
        'message_id' => $messageId,
        'sender_name' => $senderName,
        'sender_phone' => $senderPhone,
        'received_at_ms' => $receivedAtMs,
        'received_at' => $toDateTime($receivedAtMs, "sms[{$index}].received_at"),
        'content' => $sms['content'] !== null ? (string)$sms['content'] : ''
    ];
}

$activitiesPayload = [];
foreach ($activitiesInput as $index => $activity) {
    if (!is_array($activity)) {
        $respond(422, ['success' => false, 'message' => "activities[{$index}] must be an object"]);
    }
    foreach (['activity_id', 'type', 'started_at', 'duration_millis'] as $requiredField) {
        if (!array_key_exists($requiredField, $activity)) {
            $respond(422, ['success' => false, 'message' => "activities[{$index}].{$requiredField} is required"]);
        }
    }

    $activityId = $requireUnsignedBigint($activity['activity_id'], "activities[{$index}].activity_id");
    $startedAtMs = $requireUnsignedBigint($activity['started_at'], "activities[{$index}].started_at");
    $durationMillis = $requireUnsignedBigint($activity['duration_millis'], "activities[{$index}].duration_millis");
    $type = $truncate((string)$activity['type'], 100);
    if ($type === null) {
        $respond(422, ['success' => false, 'message' => "activities[{$index}].type cannot be empty"]);
    }
    $targetId = null;
    if (array_key_exists('target_id', $activity)) {
        $targetId = $truncate($activity['target_id'] !== null ? (string)$activity['target_id'] : null, 255);
    }
    $metadata = null;
    if (array_key_exists('metadata', $activity)) {
        $metadata = $truncate($activity['metadata'] !== null ? (string)$activity['metadata'] : null, 60000);
    }
    $activitiesPayload[] = [
        'activity_id' => $activityId,
        'type' => $type,
        'target_id' => $targetId,
        'metadata' => $metadata,
        'started_at_ms' => $startedAtMs,
        'started_at' => $toDateTime($startedAtMs, "activities[{$index}].started_at"),
        'duration_millis' => $durationMillis
    ];
}

try {
    $database = new Database();
    $db = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $customerCheck = $db->prepare('SELECT 1 FROM customer WHERE id = :id LIMIT 1');
    $customerCheck->execute([':id' => $customerId]);
    if (!$customerCheck->fetchColumn()) {
        $respond(404, ['success' => false, 'message' => 'Customer not found', 'customer_id' => $customerId]);
    }

    $db->beginTransaction();

    if (!empty($contactsPayload)) {
        $contactStatement = $db->prepare(
            'INSERT INTO customer_contact_uploads (customer_id, contact_id, name, phone, normalized_phone, additional_info, device_created_at_ms, device_created_at)
             VALUES (:customer_id, :contact_id, :name, :phone, :normalized_phone, :additional_info, :device_created_at_ms, :device_created_at)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                phone = VALUES(phone),
                normalized_phone = VALUES(normalized_phone),
                additional_info = VALUES(additional_info),
                device_created_at_ms = VALUES(device_created_at_ms),
                device_created_at = VALUES(device_created_at),
                created_at = CURRENT_TIMESTAMP'
        );

        foreach ($contactsPayload as $contact) {
            $contactStatement->execute([
                ':customer_id' => $customerId,
                ':contact_id' => $contact['contact_id'],
                ':name' => $contact['name'],
                ':phone' => $contact['phone'],
                ':normalized_phone' => $contact['normalized_phone'],
                ':additional_info' => $contact['additional_info'],
                ':device_created_at_ms' => $contact['created_at_ms'],
                ':device_created_at' => $contact['device_created_at']
            ]);
        }
    }

    if (!empty($smsPayload)) {
        $smsStatement = $db->prepare(
            'INSERT INTO customer_sms_uploads (customer_id, message_id, sender_name, sender_phone, received_at_ms, received_at, content)
             VALUES (:customer_id, :message_id, :sender_name, :sender_phone, :received_at_ms, :received_at, :content)
             ON DUPLICATE KEY UPDATE
                sender_name = VALUES(sender_name),
                sender_phone = VALUES(sender_phone),
                received_at_ms = VALUES(received_at_ms),
                received_at = VALUES(received_at),
                content = VALUES(content),
                created_at = CURRENT_TIMESTAMP'
        );

        foreach ($smsPayload as $smsRow) {
            $smsStatement->execute([
                ':customer_id' => $customerId,
                ':message_id' => $smsRow['message_id'],
                ':sender_name' => $smsRow['sender_name'],
                ':sender_phone' => $smsRow['sender_phone'],
                ':received_at_ms' => $smsRow['received_at_ms'],
                ':received_at' => $smsRow['received_at'],
                ':content' => $smsRow['content']
            ]);
        }
    }

    if (!empty($activitiesPayload)) {
        $activityStatement = $db->prepare(
            'INSERT INTO customer_activity_uploads (customer_id, activity_id, type, target_id, metadata, started_at_ms, started_at, duration_millis)
             VALUES (:customer_id, :activity_id, :type, :target_id, :metadata, :started_at_ms, :started_at, :duration_millis)
             ON DUPLICATE KEY UPDATE
                type = VALUES(type),
                target_id = VALUES(target_id),
                metadata = VALUES(metadata),
                started_at_ms = VALUES(started_at_ms),
                started_at = VALUES(started_at),
                duration_millis = VALUES(duration_millis),
                created_at = CURRENT_TIMESTAMP'
        );

        foreach ($activitiesPayload as $activityRow) {
            $activityStatement->execute([
                ':customer_id' => $customerId,
                ':activity_id' => $activityRow['activity_id'],
                ':type' => $activityRow['type'],
                ':target_id' => $activityRow['target_id'],
                ':metadata' => $activityRow['metadata'],
                ':started_at_ms' => $activityRow['started_at_ms'],
                ':started_at' => $activityRow['started_at'],
                ':duration_millis' => $activityRow['duration_millis']
            ]);
        }
    }

    $db->commit();

    $respond(200, [
        'success' => true,
        'message' => 'Activities uploaded successfully',
        'customer_id' => $customerId,
        'counts' => [
            'contacts' => count($contactsPayload),
            'sms' => count($smsPayload),
            'activities' => count($activitiesPayload)
        ]
    ]);
} catch (PDOException $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $respond(500, ['success' => false, 'message' => 'Server error', 'error' => $t->getMessage()]);
}
