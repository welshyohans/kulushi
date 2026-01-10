<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Addis_Ababa');

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../model/OpenRouter.php';

function extractJson(string $text): ?array
{
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $json = substr($text, $start, $end - $start + 1);
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
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

    $serviceContext = @file_get_contents(__DIR__ . '/../docs/our_service.md') ?: '';
    $client = new OpenRouterClient();

    $since = (new DateTime('-30 days'))->format('Y-m-d 00:00:00');
    $goodsStmt = $db->prepare(
        'SELECT g.id, g.name, COUNT(*) AS orders_count
         FROM ordered_list ol
         INNER JOIN orders o ON o.id = ol.orders_id
         INNER JOIN goods g ON g.id = ol.goods_id
         WHERE g.category_id = 4
           AND o.order_time >= :since
           AND o.deliver_status != 7
         GROUP BY g.id
         ORDER BY orders_count DESC
         LIMIT 5'
    );
    $goodsStmt->execute([':since' => $since]);
    $topGoods = $goodsStmt->fetchAll();

    if (!$topGoods) {
        $fallbackStmt = $db->prepare(
            'SELECT id, name FROM goods WHERE category_id = 4 ORDER BY priority DESC, id DESC LIMIT 5'
        );
        $fallbackStmt->execute();
        $topGoods = $fallbackStmt->fetchAll();
    }

    $goodsSummary = array_map(static function (array $row): string {
        return sprintf('%s (#%d)', $row['name'] ?? 'Goods', (int)$row['id']);
    }, $topGoods);
    $goodsIds = array_map(static fn(array $row): int => (int)$row['id'], $topGoods);
    $primaryGoodsId = $goodsIds[0] ?? null;

    $segments = ['potential', 'at_risk'];
    $channels = ['sms', 'fcm'];

    $now = new DateTime();
    $scheduleTimes = [
        'sms' => [10, 30],
        'fcm' => [14, 0]
    ];

    foreach ($segments as $segment) {
        foreach ($channels as $channel) {
            $time = $scheduleTimes[$channel];
            $schedule = new DateTime();
            $schedule->setTime($time[0], $time[1], 0);
            if ($schedule <= $now) {
                $schedule->modify('+1 day');
            }

            $name = sprintf('AI %s promo (%s)', strtoupper($channel), $segment);
            $existing = $db->prepare(
                'SELECT 1 FROM message_campaigns
                 WHERE name = :name
                   AND DATE(scheduled_at) = :date
                 LIMIT 1'
            );
            $existing->execute([
                ':name' => $name,
                ':date' => $schedule->format('Y-m-d')
            ]);
            if ($existing->fetchColumn()) {
                continue;
            }

            $prompt = [
                [
                    'role' => 'system',
                    'content' => "You are MerkatoPro's growth assistant. Create concise promotional messages to bring new customers and keep existing customers."
                ],
                [
                    'role' => 'user',
                    'content' => "Context:\n" . $serviceContext . "\n\nTarget segment: {$segment}\nChannel: {$channel}\nFocus: smartphone prices and availability.\nTop goods: " . implode(', ', $goodsSummary) . "\n\nReturn JSON only:\n{\n  \"title\": \"...\",\n  \"body\": \"...\",\n  \"goods_ids\": [1,2,3]\n}\nKeep SMS body under 180 chars."
                ]
            ];

            $response = $client->createChatCompletion('google/gemini-3-flash-preview', $prompt);
            $content = $response['choices'][0]['message']['content'] ?? '';
            $parsed = extractJson((string)$content);

            $title = isset($parsed['title']) && is_string($parsed['title']) ? trim($parsed['title']) : 'MerkatoPro Update';
            $body = isset($parsed['body']) && is_string($parsed['body']) ? trim($parsed['body']) : 'Check today\'s best smartphone prices on MerkatoPro.';
            $recommended = isset($parsed['goods_ids']) && is_array($parsed['goods_ids']) ? $parsed['goods_ids'] : $goodsIds;

            $dataPayload = $primaryGoodsId ? [
                'destination' => 'home_product',
                'goodsId' => (string)$primaryGoodsId
            ] : [
                'destination' => 'home'
            ];

            $insert = $db->prepare(
                'INSERT INTO message_campaigns (
                    name, channel, purpose, status, audience_type, segment,
                    min_recipients, title, body, data_payload_json,
                    scheduled_at, scheduled_tz
                 ) VALUES (
                    :name, :channel, :purpose, :status, :audience_type, :segment,
                    :min_recipients, :title, :body, :data_payload_json,
                    :scheduled_at, :scheduled_tz
                 )'
            );
            $insert->execute([
                ':name' => $name,
                ':channel' => $channel,
                ':purpose' => 'promotional',
                ':status' => 'scheduled',
                ':audience_type' => 'segment',
                ':segment' => $segment,
                ':min_recipients' => 100,
                ':title' => $title,
                ':body' => $body,
                ':data_payload_json' => json_encode([
                    'recommendedGoods' => $recommended,
                    'payload' => $dataPayload
                ]),
                ':scheduled_at' => $schedule->format('Y-m-d H:i:s'),
                ':scheduled_tz' => 'Africa/Addis_Ababa'
            ]);
        }
    }

    echo "AI campaigns generated.\n";
} catch (Throwable $exception) {
    echo 'AI campaign error: ' . $exception->getMessage() . "\n";
    exit(1);
}
