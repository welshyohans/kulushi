<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rc_respond(405, ['success' => false, 'message' => 'Method not allowed. Use POST.']);
}

$payload = rc_read_json_body();
if (!array_key_exists('customerId', $payload)) {
    rc_respond(400, ['success' => false, 'message' => 'customerId is required.']);
}

$customerId = filter_var($payload['customerId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($customerId === false) {
    rc_respond(422, ['success' => false, 'message' => 'customerId must be a positive integer.']);
}

try {
    $db = rc_connect_db();
    rc_require_referral_tables($db);
    rc_require_segment_column($db);

    $participantStmt = $db->prepare(
        'SELECT
            p.id AS participant_id,
            p.customer_id,
            p.status,
            p.joined_at,
            t.id AS tier_id,
            t.required_referrals,
            t.reward_name,
            c.segment AS registrar_segment
         FROM referral_campaign_participants p
         INNER JOIN referral_campaign_reward_tiers t ON t.id = p.selected_tier_id
         INNER JOIN customer c ON c.id = p.customer_id
         WHERE p.customer_id = :customerId
         LIMIT 1'
    );
    $participantStmt->execute([':customerId' => (int)$customerId]);
    $participant = $participantStmt->fetch();
    if (!$participant) {
        rc_respond(404, [
            'success' => false,
            'message' => 'Customer is not registered in the referral campaign.'
        ]);
    }

    $participantId = (int)$participant['participant_id'];

    $ordersAgg = '
        SELECT
            customer_id,
            COUNT(*) AS order_count,
            SUM(CASE WHEN deliver_status = 6 THEN 1 ELSE 0 END) AS delivered_count,
            MIN(order_time) AS first_order_time,
            MIN(CASE WHEN deliver_status = 6 THEN COALESCE(deliver_time, order_time) ELSE NULL END) AS first_delivered_time
        FROM orders
        WHERE deliver_status != 7
        GROUP BY customer_id
    ';

    $referralsStmt = $db->prepare(
        'SELECT
            r.id AS referral_id,
            r.referred_customer_id,
            r.referred_phone,
            r.created_at,
            rc.name,
            rc.shop_name,
            rc.phone,
            rc.segment,
            COALESCE(oa.order_count, 0) AS order_count,
            COALESCE(oa.delivered_count, 0) AS delivered_count,
            oa.first_order_time,
            oa.first_delivered_time
         FROM referral_campaign_referrals r
         INNER JOIN customer rc ON rc.id = r.referred_customer_id
         LEFT JOIN (' . $ordersAgg . ') oa ON oa.customer_id = rc.id
         WHERE r.participant_id = :participantId
         ORDER BY r.created_at DESC, r.id DESC'
    );
    $referralsStmt->execute([':participantId' => $participantId]);
    $referralRows = $referralsStmt->fetchAll();

    $qualifiedCount = 0;
    $referrals = array_map(static function (array $row) use (&$qualifiedCount): array {
        $orderCount = (int)$row['order_count'];
        $deliveredCount = (int)$row['delivered_count'];
        $hasOrdered = $orderCount > 0;
        $hasDelivered = $deliveredCount > 0;
        if ($hasDelivered) {
            $qualifiedCount++;
        }

        return [
            'referralId' => (int)$row['referral_id'],
            'referredCustomerId' => (int)$row['referred_customer_id'],
            'referredPhone' => (string)$row['referred_phone'],
            'customer' => [
                'name' => (string)($row['name'] ?? ''),
                'shopName' => (string)($row['shop_name'] ?? ''),
                'phone' => (string)($row['phone'] ?? ''),
                'segment' => (string)($row['segment'] ?? '')
            ],
            'createdAt' => (string)$row['created_at'],
            'hasOrdered' => $hasOrdered,
            'hasDelivered' => $hasDelivered,
            'firstOrderTime' => $row['first_order_time'],
            'firstDeliveredTime' => $row['first_delivered_time']
        ];
    }, $referralRows);

    $claimStmt = $db->prepare(
        'SELECT claim_status, requested_at, processed_at, note
         FROM referral_campaign_reward_claims
         WHERE participant_id = :participantId
         LIMIT 1'
    );
    $claimStmt->execute([':participantId' => $participantId]);
    $claim = $claimStmt->fetch();

    $required = (int)$participant['required_referrals'];
    $canClaim = $qualifiedCount >= $required && !$claim;

    rc_respond(200, [
        'success' => true,
        'participant' => [
            'participantId' => $participantId,
            'customerId' => (int)$participant['customer_id'],
            'status' => (string)$participant['status'],
            'joinedAt' => (string)$participant['joined_at'],
            'segment' => (string)$participant['registrar_segment'],
            'tier' => [
                'tierId' => (int)$participant['tier_id'],
                'requiredReferrals' => $required,
                'rewardName' => (string)$participant['reward_name']
            ]
        ],
        'progress' => [
            'totalReferred' => count($referrals),
            'qualifiedDelivered' => $qualifiedCount,
            'remainingToGoal' => max($required - $qualifiedCount, 0),
            'canClaimReward' => $canClaim
        ],
        'claim' => $claim ? [
            'status' => (string)$claim['claim_status'],
            'requestedAt' => (string)$claim['requested_at'],
            'processedAt' => $claim['processed_at'],
            'note' => $claim['note']
        ] : null,
        'referrals' => $referrals
    ]);
} catch (PDOException $exception) {
    rc_respond(500, ['success' => false, 'message' => 'Database error', 'error' => $exception->getMessage()]);
} catch (Throwable $throwable) {
    rc_respond(500, ['success' => false, 'message' => 'Server error', 'error' => $throwable->getMessage()]);
}

