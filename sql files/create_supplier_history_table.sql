CREATE TABLE IF NOT EXISTS supplier_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    goods_id INT UNSIGNED NOT NULL,
    action VARCHAR(64) NOT NULL,
    details VARCHAR(255) DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_supplier (supplier_id),
    INDEX idx_goods (goods_id),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_supplier_history_supplier
        FOREIGN KEY (supplier_id) REFERENCES supplier (shop_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_supplier_history_goods
        FOREIGN KEY (goods_id) REFERENCES goods (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;