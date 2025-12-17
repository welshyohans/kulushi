CREATE TABLE IF NOT EXISTS customer_chat_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    user_message TEXT NOT NULL,
    assistant_message TEXT NOT NULL,
    model VARCHAR(191) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_created (customer_id, created_at),
    CONSTRAINT fk_customer_chat_records_customer
        FOREIGN KEY (customer_id) REFERENCES customer(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
