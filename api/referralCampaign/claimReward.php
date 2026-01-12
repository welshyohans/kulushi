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

    $registrar = rc_load_customer($db, (int)$customerId);
    if (!rc_registrar_segment_allowed($registrar['segment'])) {
        rc_respond(403, [
            'success' => false,
            'message' => 'Customer is not eligible for this campaign (segment must be active).',
            'segment' => $registrar['segment']
        ]);
    }

    $participantStmt = $db->prepare(
        'SELECT p.id AS participant_id, t.required_referrals
         FROM referral_campaign_participants p
         INNER JOIN referral_campaign_reward_tiers t ON t.id = p.selected_tier_id
         WHERE p.customer_id = :customerId AND p.status = :status
         LIMIT 1'
    );
    $participantStmt->execute([
        ':customerId' => (int)$customerId,
        ':status' => 'active'
    ]);
    $participant = $participantStmt->fetch();
    if (!$participant) {
        rc_respond(404, ['success' => false, 'message' => 'Customer is not registered in the referral campaign.']);
    }

    $participantId = (int)$participant['participant_id'];
    $required = (int)$participant['required_referrals'];

    $existingClaimStmt = $db->prepare(
        'SELECT id, claim_status, requested_at, processed_at, note
         FROM referral_campaign_reward_claims
         WHERE participant_id = :participantId
         LIMIT 1'
    );
    $existingClaimStmt->execute([':participantId' => $participantId]);
    $existingClaim = $existingClaimStmt->fetch();
    if ($existingClaim) {
        rc_respond(200, [
            'success' => true,
            'alreadyRequested' => true,
            'claim' => [
                'claimId' => (int)$existingClaim['id'],
                'status' => (string)$existingClaim['claim_status'],
                'requestedAt' => (string)$existingClaim['requested_at'],
                'processedAt' => $existingClaim['processed_at'],
                'note' => $existingClaim['note']
            ]
        ]);
    }

    $qualifiedCountStmt = $db->prepare(
        'SELECT COUNT(*) AS qualified
         FROM referral_campaign_referrals r
         WHERE r.participant_id = :participantId
           AND EXISTS (
             SELECT 1
             FROM orders o
             WHERE o.customer_id = r.referred_customer_id
               AND o.deliver_status = 6
             LIMIT 1
           )'
    );
    $qualifiedCountStmt->execute([':participantId' => $participantId]);
    $qualifiedCount = (int)(($qualifiedCountStmt->fetch() ?: ['qualified' => 0])['qualified']);

    if ($qualifiedCount < $required) {
        rc_respond(409, [
            'success' => false,
            'message' => 'Reward cannot be claimed yet. Not enough delivered referrals.',
            'qualifiedDelivered' => $qualifiedCount,
            'requiredReferrals' => $required
        ]);
    }

    $insertClaimStmt = $db->prepare(
        'INSERT INTO referral_campaign_reward_claims (participant_id, claim_status)
         VALUES (:participantId, :status)'
    );
    $insertClaimStmt->execute([
        ':participantId' => $participantId,
        ':status' => 'requested'
    ]);
    $claimId = (int)$db->lastInsertId();

    rc_respond(201, [
        'success' => true,
        'alreadyRequested' => false,
        'claim' => [
            'claimId' => $claimId,
            'status' => 'requested',
            'qualifiedDelivered' => $qualifiedCount,
            'requiredReferrals' => $required
        ]
    ]);
} catch (PDOException $exception) {
    rc_respond(500, ['success' => false, 'message' => 'Database error', 'error' => $exception->getMessage()]);
} catch (Throwable $throwable) {
    rc_respond(500, ['success' => false, 'message' => 'Server error', 'error' => $throwable->getMessage()]);
}

