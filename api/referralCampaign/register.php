<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rc_respond(405, ['success' => false, 'message' => 'Method not allowed. Use POST.']);
}

$payload = rc_read_json_body();

if (!array_key_exists('customerId', $payload) || !array_key_exists('tierId', $payload)) {
    rc_respond(400, ['success' => false, 'message' => 'customerId and tierId are required.']);
}

$customerId = filter_var($payload['customerId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$tierId = filter_var($payload['tierId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($customerId === false || $tierId === false) {
    rc_respond(422, ['success' => false, 'message' => 'customerId and tierId must be positive integers.']);
}

$allowChange = isset($payload['allowChange']) ? (bool)$payload['allowChange'] : false;

try {
    $db = rc_connect_db();
    rc_require_referral_tables($db);

    $registrar = rc_load_customer($db, (int)$customerId);
    if (!rc_registrar_segment_allowed($registrar['segment'])) {
        rc_respond(403, [
            'success' => false,
            'message' => 'Customer is not eligible for this campaign (segment must be active).',
            'segment' => $registrar['segment']
        ]);
    }

    $tierStmt = $db->prepare(
        'SELECT id, required_referrals, reward_name
         FROM referral_campaign_reward_tiers
         WHERE id = :id AND is_active = 1
         LIMIT 1'
    );
    $tierStmt->execute([':id' => (int)$tierId]);
    $tier = $tierStmt->fetch();
    if (!$tier) {
        rc_respond(404, ['success' => false, 'message' => 'Reward tier not found.', 'tierId' => (int)$tierId]);
    }

    $participantStmt = $db->prepare(
        'SELECT p.id, p.selected_tier_id
         FROM referral_campaign_participants p
         WHERE p.customer_id = :customerId
         LIMIT 1'
    );
    $participantStmt->execute([':customerId' => (int)$customerId]);
    $participant = $participantStmt->fetch();

    if ($participant) {
        $participantId = (int)$participant['id'];
        $currentTierId = (int)$participant['selected_tier_id'];

        if ($currentTierId !== (int)$tierId && $allowChange) {
            $hasRefsStmt = $db->prepare('SELECT 1 FROM referral_campaign_referrals WHERE participant_id = :pid LIMIT 1');
            $hasRefsStmt->execute([':pid' => $participantId]);
            $hasReferrals = (bool)$hasRefsStmt->fetchColumn();

            $hasClaimStmt = $db->prepare('SELECT 1 FROM referral_campaign_reward_claims WHERE participant_id = :pid LIMIT 1');
            $hasClaimStmt->execute([':pid' => $participantId]);
            $hasClaim = (bool)$hasClaimStmt->fetchColumn();

            if (!$hasReferrals && !$hasClaim) {
                $updateStmt = $db->prepare('UPDATE referral_campaign_participants SET selected_tier_id = :tierId WHERE id = :pid');
                $updateStmt->execute([':tierId' => (int)$tierId, ':pid' => $participantId]);
                $currentTierId = (int)$tierId;
            }
        }

        rc_respond(200, [
            'success' => true,
            'alreadyRegistered' => true,
            'participantId' => $participantId,
            'customerId' => (int)$customerId,
            'tierId' => $currentTierId
        ]);
    }

    $insertStmt = $db->prepare(
        'INSERT INTO referral_campaign_participants (customer_id, selected_tier_id)
         VALUES (:customerId, :tierId)'
    );
    $insertStmt->execute([':customerId' => (int)$customerId, ':tierId' => (int)$tierId]);
    $participantId = (int)$db->lastInsertId();

    rc_respond(201, [
        'success' => true,
        'alreadyRegistered' => false,
        'participantId' => $participantId,
        'customerId' => (int)$customerId,
        'tier' => [
            'tierId' => (int)$tier['id'],
            'requiredReferrals' => (int)$tier['required_referrals'],
            'rewardName' => (string)$tier['reward_name']
        ]
    ]);
} catch (PDOException $exception) {
    rc_respond(500, ['success' => false, 'message' => 'Database error', 'error' => $exception->getMessage()]);
} catch (Throwable $throwable) {
    rc_respond(500, ['success' => false, 'message' => 'Server error', 'error' => $throwable->getMessage()]);
}
