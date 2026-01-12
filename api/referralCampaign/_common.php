<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/Database.php';

function rc_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function rc_connect_db(): PDO
{
    $database = new Database();
    $db = $database->connect();
    if (!$db instanceof PDO) {
        rc_respond(500, ['success' => false, 'message' => 'Database connection failed.']);
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $db;
}

function rc_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        rc_respond(400, ['success' => false, 'message' => 'Unable to read request body.']);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        rc_respond(400, ['success' => false, 'message' => 'Invalid JSON body.']);
    }

    return $data;
}

function rc_standardize_phone(string $input): string
{
    $digits = preg_replace('/\\D+/', '', $input);
    if (!is_string($digits) || $digits === '') {
        rc_respond(422, ['success' => false, 'message' => 'phone is invalid']);
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

    rc_respond(422, ['success' => false, 'message' => 'phone format is not supported']);
}

function rc_column_exists(PDO $db, string $tableName, string $columnName): bool
{
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$tableName` LIKE " . $db->quote($columnName));
        return $stmt->rowCount() > 0;
    } catch (Throwable $throwable) {
        return false;
    }
}

function rc_table_exists(PDO $db, string $tableName): bool
{
    try {
        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($tableName));
        return $stmt->rowCount() > 0;
    } catch (Throwable $throwable) {
        return false;
    }
}

function rc_require_referral_tables(PDO $db): void
{
    $required = [
        'referral_campaign_reward_tiers',
        'referral_campaign_participants',
        'referral_campaign_referrals',
        'referral_campaign_reward_claims'
    ];

    foreach ($required as $tableName) {
        if (!rc_table_exists($db, $tableName)) {
            rc_respond(400, [
                'success' => false,
                'message' => 'Referral campaign tables not found. Please run the migration: sql files/referral_campaign_migration.sql',
                'missingTable' => $tableName
            ]);
        }
    }
}

function rc_require_segment_column(PDO $db): void
{
    if (!rc_column_exists($db, 'customer', 'segment')) {
        rc_respond(400, [
            'success' => false,
            'message' => 'customer.segment column not found. Please run the migration: sql files/admin_dashboard_migration.sql'
        ]);
    }
}

/**
 * Returns: id, segment
 */
function rc_load_customer(PDO $db, int $customerId): array
{
    rc_require_segment_column($db);

    $stmt = $db->prepare('SELECT id, segment FROM customer WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $customerId]);
    $row = $stmt->fetch();
    if (!$row) {
        rc_respond(404, ['success' => false, 'message' => 'Customer not found.', 'customerId' => $customerId]);
    }

    return [
        'id' => (int)$row['id'],
        'segment' => (string)$row['segment']
    ];
}

function rc_registrar_segment_allowed(string $segment): bool
{
    return in_array($segment, ['active', 'loyal', 'vip'], true);
}
