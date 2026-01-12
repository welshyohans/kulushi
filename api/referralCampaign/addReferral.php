<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../model/SMS.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rc_respond(405, ['success' => false, 'message' => 'Method not allowed. Use POST.']);
}

$payload = rc_read_json_body();

if (!array_key_exists('registrarCustomerId', $payload) || !array_key_exists('phone', $payload)) {
    rc_respond(400, ['success' => false, 'message' => 'registrarCustomerId and phone are required.']);
}

$registrarCustomerId = filter_var($payload['registrarCustomerId'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);
if ($registrarCustomerId === false) {
    rc_respond(422, ['success' => false, 'message' => 'registrarCustomerId must be a positive integer.']);
}

$phone = rc_standardize_phone((string)$payload['phone']);

$name = isset($payload['name']) ? trim((string)$payload['name']) : '';
$shopName = isset($payload['shopName']) ? trim((string)$payload['shopName']) : '';
$addressId = isset($payload['addressId']) ? (int)$payload['addressId'] : 0;

try {
    $db = rc_connect_db();
    rc_require_referral_tables($db);
    rc_require_segment_column($db);

    $registrar = rc_load_customer($db, (int)$registrarCustomerId);
    if (!rc_registrar_segment_allowed($registrar['segment'])) {
        rc_respond(403, [
            'success' => false,
            'message' => 'Registrar customer is not eligible for this campaign (segment must be active).',
            'segment' => $registrar['segment']
        ]);
    }

    $participantStmt = $db->prepare(
        'SELECT id
         FROM referral_campaign_participants
         WHERE customer_id = :customerId AND status = :status
         LIMIT 1'
    );
    $participantStmt->execute([
        ':customerId' => (int)$registrarCustomerId,
        ':status' => 'active'
    ]);
    $participantId = $participantStmt->fetchColumn();
    if ($participantId === false) {
        rc_respond(409, [
            'success' => false,
            'message' => 'Registrar customer is not registered in the referral campaign yet.'
        ]);
    }

    $db->beginTransaction();

    $existingCustomerStmt = $db->prepare('SELECT id, segment FROM customer WHERE phone = :phone LIMIT 1');
    $existingCustomerStmt->execute([':phone' => $phone]);
    $existingCustomer = $existingCustomerStmt->fetch();

    $referredCustomerId = null;
    $createdNewCustomer = false;
    $passwordForNewCustomer = null;

    if ($existingCustomer) {
        $referredCustomerId = (int)$existingCustomer['id'];
        if ($referredCustomerId === (int)$registrarCustomerId) {
            $db->rollBack();
            rc_respond(422, ['success' => false, 'message' => 'You cannot refer yourself.']);
        }

        $segment = (string)$existingCustomer['segment'];
        if (!in_array($segment, ['potential', 'new'], true)) {
            $db->rollBack();
            rc_respond(403, [
                'success' => false,
                'message' => "This phone is already registered, but customer segment is '{$segment}'. Only 'potential' or 'new' can be added to the campaign.",
                'segment' => $segment
            ]);
        }
    } else {
        if ($name === '' || $shopName === '' || $addressId <= 0) {
            $db->rollBack();
            rc_respond(422, [
                'success' => false,
                'message' => 'name, shopName, and addressId are required when the phone is not registered.'
            ]);
        }

        $addressStatement = $db->prepare('SELECT sub_city FROM address WHERE id = :id LIMIT 1');
        $addressStatement->execute([':id' => $addressId]);
        $addressRow = $addressStatement->fetch();
        if (!$addressRow) {
            $db->rollBack();
            rc_respond(404, ['success' => false, 'message' => 'Address not found', 'addressId' => $addressId]);
        }

        $specificAddress = (string)$addressRow['sub_city'];
        $passwordForNewCustomer = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $insertCustomerStmt = $db->prepare(
            'INSERT INTO customer (name, phone, shop_name, password, registered_by, address_id, specific_address, location, location_description, user_type, firebase_code, fast_delivery_value, use_telegram, total_credit, total_unpaid, permitted_credit, delivery_time_info)
             VALUES (:name, :phone, :shop_name, :password, :registered_by, :address_id, :specific_address, :location, :location_description, :user_type, :firebase_code, :fast_delivery_value, :use_telegram, :total_credit, :total_unpaid, :permitted_credit, :delivery_time_info)'
        );
        $insertCustomerStmt->execute([
            ':name' => $name,
            ':phone' => $phone,
            ':shop_name' => $shopName,
            ':password' => $passwordForNewCustomer,
            ':registered_by' => (int)$registrarCustomerId,
            ':address_id' => $addressId,
            ':specific_address' => $specificAddress,
            ':location' => '',
            ':location_description' => '',
            ':user_type' => '0',
            ':firebase_code' => '0',
            ':fast_delivery_value' => -1,
            ':use_telegram' => 0,
            ':total_credit' => 0,
            ':total_unpaid' => 0,
            ':permitted_credit' => 0,
            ':delivery_time_info' => ''
        ]);

        $referredCustomerId = (int)$db->lastInsertId();
        $createdNewCustomer = true;

        $addressInsert = $db->prepare(
            'INSERT INTO customer_address (customer_id, address_id, address_name, is_main_address)
             VALUES (:customer_id, :address_id, :address_name, :is_main_address)'
        );
        $addressInsert->execute([
            ':customer_id' => $referredCustomerId,
            ':address_id' => $addressId,
            ':address_name' => $specificAddress,
            ':is_main_address' => 1
        ]);
    }

    $insertReferralStmt = $db->prepare(
        'INSERT INTO referral_campaign_referrals (participant_id, registrar_customer_id, referred_customer_id, referred_phone)
         VALUES (:participant_id, :registrar_customer_id, :referred_customer_id, :referred_phone)'
    );
    $insertReferralStmt->execute([
        ':participant_id' => (int)$participantId,
        ':registrar_customer_id' => (int)$registrarCustomerId,
        ':referred_customer_id' => (int)$referredCustomerId,
        ':referred_phone' => $phone
    ]);
    $referralId = (int)$db->lastInsertId();

    $db->commit();

    if ($createdNewCustomer && $passwordForNewCustomer !== null) {
        $sms = new SMS();
        $nationalPhone = substr($phone, -9);
        $recipient = '+251' . $nationalPhone;
        $message = "Welcome to Merkato Pro. Your password is {$passwordForNewCustomer}.";
        $sms->sendSms($recipient, $message);
    }

    rc_respond(201, [
        'success' => true,
        'message' => 'Referral added to campaign.',
        'referralId' => $referralId,
        'registrarCustomerId' => (int)$registrarCustomerId,
        'referredCustomerId' => (int)$referredCustomerId,
        'createdNewCustomer' => $createdNewCustomer
    ]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    $sqlState = $exception->getCode();
    if ($sqlState === '23000') {
        rc_respond(409, [
            'success' => false,
            'message' => 'This customer was already added to a referral campaign (duplicate).'
        ]);
    }

    rc_respond(500, ['success' => false, 'message' => 'Database error', 'error' => $exception->getMessage()]);
} catch (Throwable $throwable) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    rc_respond(500, ['success' => false, 'message' => 'Server error', 'error' => $throwable->getMessage()]);
}
