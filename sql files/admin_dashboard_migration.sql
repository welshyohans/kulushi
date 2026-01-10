-- Admin dashboard schema additions for MerkatoPro

START TRANSACTION;

-- Snapshot pricing/profit per order line
ALTER TABLE `ordered_list`
  ADD COLUMN `supplier_price` decimal(12,2) NOT NULL DEFAULT 0 AFTER `each_price`,
  ADD COLUMN `commission` decimal(12,2) NOT NULL DEFAULT 0 AFTER `supplier_price`,
  ADD COLUMN `line_profit` decimal(12,2) NOT NULL DEFAULT 0 AFTER `commission`;

-- Backfill snapshot values for existing order lines
UPDATE `ordered_list` ol
LEFT JOIN `supplier_goods` sg ON sg.id = ol.supplier_goods_id
LEFT JOIN `goods` g ON g.id = ol.goods_id
SET ol.supplier_price = COALESCE(sg.price, 0),
    ol.commission = COALESCE(g.commission, 0),
    ol.line_profit = COALESCE(g.commission, 0) * COALESCE(ol.quantity, 0)
WHERE ol.supplier_price = 0
  AND ol.commission = 0;

-- Backfill orders.profit from line_profit snapshots
UPDATE `orders` o
INNER JOIN (
    SELECT orders_id, COALESCE(SUM(COALESCE(line_profit, 0)), 0) AS total_profit
    FROM ordered_list
    WHERE status != -1
    GROUP BY orders_id
) t ON t.orders_id = o.id
SET o.profit = t.total_profit;

-- Customer segmentation + manual adjustments
ALTER TABLE `customer`
  ADD COLUMN `segment` varchar(20) NOT NULL DEFAULT 'new' AFTER `user_type`,
  ADD COLUMN `is_lead` tinyint(1) NOT NULL DEFAULT 0 AFTER `segment`,
  ADD COLUMN `lead_source` varchar(50) DEFAULT NULL AFTER `is_lead`,
  ADD COLUMN `manual_profit` decimal(12,2) NOT NULL DEFAULT 0 AFTER `total_unpaid`,
  ADD COLUMN `manual_loss` decimal(12,2) NOT NULL DEFAULT 0 AFTER `manual_profit`,
  ADD COLUMN `manual_credit` decimal(12,2) NOT NULL DEFAULT 0 AFTER `manual_loss`;

CREATE INDEX `idx_customer_segment` ON `customer` (`segment`);
CREATE INDEX `idx_customer_is_lead` ON `customer` (`is_lead`);

-- Daily expenses ledger
CREATE TABLE `daily_expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_daily_expenses_date` (`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campaigns + delivery logs
CREATE TABLE `message_campaigns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `channel` varchar(10) NOT NULL,
  `purpose` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `audience_type` varchar(20) NOT NULL DEFAULT 'all',
  `segment` varchar(20) DEFAULT NULL,
  `address_id` int(11) DEFAULT NULL,
  `min_recipients` int(11) NOT NULL DEFAULT 0,
  `max_recipients` int(11) NOT NULL DEFAULT 0,
  `title` varchar(200) DEFAULT NULL,
  `body` text NOT NULL,
  `data_payload_json` text DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `scheduled_tz` varchar(64) NOT NULL DEFAULT 'Africa/Addis_Ababa',
  `created_by` int(11) DEFAULT NULL,
  `status_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_status` (`status`),
  KEY `idx_campaign_schedule` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` bigint(20) unsigned NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `channel` varchar(10) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `fcm_token` text DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `body` text NOT NULL,
  `data_payload_json` text DEFAULT NULL,
  `provider_message_id` varchar(255) DEFAULT NULL,
  `provider_status` varchar(50) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_message_deliveries_campaign` (`campaign_id`),
  KEY `idx_message_deliveries_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(30) NOT NULL,
  `event_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `metadata_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_message_events_delivery` (`delivery_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery runs + opt-ins
CREATE TABLE `delivery_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `address_id` int(11) NOT NULL,
  `run_date` date NOT NULL,
  `eta_window` varchar(100) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'planned',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_delivery_runs_address` (`address_id`),
  KEY `idx_delivery_runs_date` (`run_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_run_optins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `address_id` int(11) NOT NULL,
  `opt_in` tinyint(1) NOT NULL DEFAULT 1,
  `channel_preference` varchar(10) NOT NULL DEFAULT 'both',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_delivery_optin` (`customer_id`, `address_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manual inventory ledger
CREATE TABLE `inventory_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `goods_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0,
  `entry_type` varchar(20) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inventory_goods` (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure an "Unknown" address exists for potential leads (optional)
INSERT INTO `address` (`city`, `sub_city`, `last_update_code`, `has_supplier`)
SELECT 'Unknown', 'Unknown', 0, 0
WHERE NOT EXISTS (
    SELECT 1 FROM `address` WHERE `city` = 'Unknown' AND `sub_city` = 'Unknown' LIMIT 1
);

COMMIT;
