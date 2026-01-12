<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    rc_respond(405, ['success' => false, 'message' => 'Method not allowed. Use GET.']);
}

try {
    $db = rc_connect_db();
    rc_require_referral_tables($db);

    $stmt = $db->prepare(
        'SELECT id, required_referrals, reward_name
         FROM referral_campaign_reward_tiers
         WHERE is_active = 1
         ORDER BY required_referrals ASC, id ASC'
    );
    $stmt->execute();
    $tiers = array_map(static function (array $row): array {
        return [
            'tierId' => (int)$row['id'],
            'requiredReferrals' => (int)$row['required_referrals'],
            'rewardName' => (string)$row['reward_name']
        ];
    }, $stmt->fetchAll());

    rc_respond(200, ['success' => true, 'tiers' => $tiers]);
} catch (PDOException $exception) {
    rc_respond(500, ['success' => false, 'message' => 'Database error', 'error' => $exception->getMessage()]);
} catch (Throwable $throwable) {
    rc_respond(500, ['success' => false, 'message' => 'Server error', 'error' => $throwable->getMessage()]);
}
