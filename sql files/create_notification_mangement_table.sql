CREATE TABLE IF NOT EXISTS notification_mangement (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    fcm_payment TINYINT(1) NOT NULL DEFAULT 1,
    fcm_delivering TINYINT(1) NOT NULL DEFAULT 1,
    fcm_ordering TINYINT(1) NOT NULL DEFAULT 1,
    fcm_price_change TINYINT(1) NOT NULL DEFAULT 1,
    fcm_new_product TINYINT(1) NOT NULL DEFAULT 1,
    sms_payment TINYINT(1) NOT NULL DEFAULT 1,
    sms_delivering TINYINT(1) NOT NULL DEFAULT 1,
    sms_ordering TINYINT(1) NOT NULL DEFAULT 1,
    sms_ads TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_notification_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;