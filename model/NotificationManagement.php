<?php

class NotificationManagement
{
    private $conn;
    private $table = 'notification_mangement';

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    public function getByCustomerId(int $customerId): ?array
    {
        $sql = "SELECT 
                    id,
                    customer_id,
                    fcm_payment,
                    fcm_delivering,
                    fcm_ordering,
                    fcm_price_change,
                    fcm_new_product,
                    sms_payment,
                    sms_delivering,
                    sms_ordering,
                    sms_ads
                FROM {$this->table}
                WHERE customer_id = :customerId
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':customerId', $customerId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function upsert(array $payload): array
    {
        $sql = "INSERT INTO {$this->table} (
                    customer_id,
                    fcm_payment,
                    fcm_delivering,
                    fcm_ordering,
                    fcm_price_change,
                    fcm_new_product,
                    sms_payment,
                    sms_delivering,
                    sms_ordering,
                    sms_ads
                ) VALUES (
                    :customer_id,
                    :fcm_payment,
                    :fcm_delivering,
                    :fcm_ordering,
                    :fcm_price_change,
                    :fcm_new_product,
                    :sms_payment,
                    :sms_delivering,
                    :sms_ordering,
                    :sms_ads
                )
                ON DUPLICATE KEY UPDATE
                    fcm_payment = VALUES(fcm_payment),
                    fcm_delivering = VALUES(fcm_delivering),
                    fcm_ordering = VALUES(fcm_ordering),
                    fcm_price_change = VALUES(fcm_price_change),
                    fcm_new_product = VALUES(fcm_new_product),
                    sms_payment = VALUES(sms_payment),
                    sms_delivering = VALUES(sms_delivering),
                    sms_ordering = VALUES(sms_ordering),
                    sms_ads = VALUES(sms_ads)";

        $stmt = $this->conn->prepare($sql);

        $stmt->bindValue(':customer_id', $payload['customer_id'], PDO::PARAM_INT);
        $stmt->bindValue(':fcm_payment', $payload['fcm_payment'], PDO::PARAM_INT);
        $stmt->bindValue(':fcm_delivering', $payload['fcm_delivering'], PDO::PARAM_INT);
        $stmt->bindValue(':fcm_ordering', $payload['fcm_ordering'], PDO::PARAM_INT);
        $stmt->bindValue(':fcm_price_change', $payload['fcm_price_change'], PDO::PARAM_INT);
        $stmt->bindValue(':fcm_new_product', $payload['fcm_new_product'], PDO::PARAM_INT);
        $stmt->bindValue(':sms_payment', $payload['sms_payment'], PDO::PARAM_INT);
        $stmt->bindValue(':sms_delivering', $payload['sms_delivering'], PDO::PARAM_INT);
        $stmt->bindValue(':sms_ordering', $payload['sms_ordering'], PDO::PARAM_INT);
        $stmt->bindValue(':sms_ads', $payload['sms_ads'], PDO::PARAM_INT);

        $stmt->execute();

        return $this->getByCustomerId((int)$payload['customer_id']) ?? [];
    }
}