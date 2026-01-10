<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Addis_Ababa');

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../model/SMS.php';
require_once __DIR__ . '/../model/FCM.php';

function normalisePhoneNumber(?string $rawPhone): ?string
{
    if ($rawPhone === null) {
        return null;
    }
    $sanitised = preg_replace('/[^\d+]/', '', trim($rawPhone));
    if ($sanitised === null || $sanitised === '') {
        return null;
    }
    if (strpos($sanitised, '+') === 0) {
        return $sanitised;
    }
    if (strpos($sanitised, '0') === 0) {
        return '+251' . substr($sanitised, 1);
    }
    if (strpos($sanitised, '251') === 0) {
        return '+' . $sanitised;
    }
    if (preg_match('/^\d{9,12}$/', $sanitised)) {
        return '+251' . $sanitised;
    }
    return null;
}

function preferenceColumn(string $channel, string $purpose): string
{
    $purpose = strtolower($purpose);
    if ($channel === 'sms') {
        if ($purpose === 'delivering') {
            return 'sms_delivering';
        }
        if ($purpose === 'ordering') {
            return 'sms_ordering';
        }
        if ($purpose === 'price_change' || $purpose === 'new_product' || $purpose === 'promotional') {
            return 'sms_ads';
        }
        return 'sms_ads';
    }

    if ($purpose === 'delivering') {
        return 'fcm_delivering';
    }
    if ($purpose === 'ordering') {
        return 'fcm_ordering';
    }
    if ($purpose === 'new_product') {
        return 'fcm_new_product';
    }
    return 'fcm_price_change';
}

function fetchRecipients(PDO $db, array $campaign, string $channel): array
{
    $where = [];
    $params = [];

    if ($channel === 'sms') {
        $where[] = "c.phone IS NOT NULL AND c.phone <> ''";
    } else {
        $where[] = "c.firebase_code IS NOT NULL AND c.firebase_code <> '' AND c.firebase_code <> '0'";
    }

    if (!empty($campaign['segment'])) {
        $where[] = 'c.segment = :segment';
        $params[':segment'] = $campaign['segment'];
    }

    if (!empty($campaign['address_id'])) {
        $where[] = 'c.address_id = :address_id';
        $params[':address_id'] = (int)$campaign['address_id'];
    }

    $prefColumn = preferenceColumn($channel, (string)$campaign['purpose']);
    $where[] = '(COALESCE(c.is_lead, 0) = 1 OR COALESCE(nm.' . $prefColumn . ', 1) = 1)';

    $sql = 'SELECT c.id, c.phone, c.firebase_code
            FROM customer c
            LEFT JOIN notification_mangement nm ON nm.customer_id = c.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY c.register_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

try {
    $database = new Database();
    $db = $database->connect();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Database connection failed.');
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $now = (new DateTime())->format('Y-m-d H:i:s');

    $campaignStmt = $db->prepare(
        'SELECT *
         FROM message_campaigns
         WHERE status = :status
           AND scheduled_at IS NOT NULL
           AND scheduled_at <= :now
         ORDER BY scheduled_at ASC
         LIMIT 10'
    );
    $campaignStmt->execute([':status' => 'scheduled', ':now' => $now]);
    $campaigns = $campaignStmt->fetchAll();

    if (!$campaigns) {
        echo "No campaigns to dispatch.\n";
        exit;
    }

    $sms = new SMS();
    $fcm = new FCMService('from-merkato', __DIR__ . '/../model/mp.json');

    foreach ($campaigns as $campaign) {
        $campaignId = (int)$campaign['id'];
        $db->prepare('UPDATE message_campaigns SET status = :status WHERE id = :id')
            ->execute([':status' => 'sending', ':id' => $campaignId]);

        $channels = $campaign['channel'] === 'both' ? ['sms', 'fcm'] : [$campaign['channel']];
        $minRecipients = (int)$campaign['min_recipients'];
        $maxRecipients = (int)$campaign['max_recipients'];
        $blockedReason = null;

        $channelRecipients = [];
        foreach ($channels as $channel) {
            $recipients = fetchRecipients($db, $campaign, $channel);
            $recipientCount = count($recipients);

            if ($minRecipients > 0 && $recipientCount < $minRecipients) {
                $blockedReason = sprintf(
                    'Not enough %s recipients (%d/%d).',
                    $channel,
                    $recipientCount,
                    $minRecipients
                );
                break;
            }

            if ($maxRecipients > 0 && $recipientCount > $maxRecipients) {
                $recipients = array_slice($recipients, 0, $maxRecipients);
            }

            $channelRecipients[$channel] = $recipients;
        }

        if ($blockedReason !== null) {
            $db->prepare('UPDATE message_campaigns SET status = :status, status_note = :note WHERE id = :id')
                ->execute([':status' => 'blocked', ':note' => $blockedReason, ':id' => $campaignId]);
            continue;
        }

        foreach ($channelRecipients as $channel => $recipients) {
            foreach ($recipients as $recipient) {
                $customerId = (int)$recipient['id'];
                $title = $campaign['title'] ?? '';
                $body = (string)$campaign['body'];
                $dataPayload = $campaign['data_payload_json']
                    ? json_decode($campaign['data_payload_json'], true)
                    : [];

                if ($channel === 'sms') {
                    $phone = normalisePhoneNumber($recipient['phone'] ?? '');
                    if ($phone === null) {
                        continue;
                    }
                    $sms->sendSms($phone, $body);

                    $insert = $db->prepare(
                        'INSERT INTO message_deliveries
                         (campaign_id, customer_id, channel, phone, title, body, data_payload_json, sent_at)
                         VALUES (:campaign_id, :customer_id, :channel, :phone, :title, :body, :data_payload_json, NOW())'
                    );
                    $insert->execute([
                        ':campaign_id' => $campaignId,
                        ':customer_id' => $customerId,
                        ':channel' => 'sms',
                        ':phone' => $phone,
                        ':title' => $title,
                        ':body' => $body,
                        ':data_payload_json' => $campaign['data_payload_json']
                    ]);
                } else {
                    $token = $recipient['firebase_code'] ?? '';
                    if ($token === '') {
                        continue;
                    }
                    $fcm->sendFCM($token, $title, $body, is_array($dataPayload) ? $dataPayload : []);

                    $insert = $db->prepare(
                        'INSERT INTO message_deliveries
                         (campaign_id, customer_id, channel, fcm_token, title, body, data_payload_json, sent_at)
                         VALUES (:campaign_id, :customer_id, :channel, :fcm_token, :title, :body, :data_payload_json, NOW())'
                    );
                    $insert->execute([
                        ':campaign_id' => $campaignId,
                        ':customer_id' => $customerId,
                        ':channel' => 'fcm',
                        ':fcm_token' => $token,
                        ':title' => $title,
                        ':body' => $body,
                        ':data_payload_json' => $campaign['data_payload_json']
                    ]);
                }
            }
        }

        $db->prepare('UPDATE message_campaigns SET status = :status, sent_at = NOW() WHERE id = :id')
            ->execute([':status' => 'sent', ':id' => $campaignId]);
    }
} catch (Throwable $exception) {
    echo 'Dispatch error: ' . $exception->getMessage() . "\n";
    exit(1);
}
