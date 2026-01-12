-- Referral campaign (customer invites) schema
-- Run after base schema and (recommended) after: sql files/admin_dashboard_migration.sql

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `referral_campaign_reward_tiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `required_referrals` int(11) NOT NULL,
  `reward_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_referral_tiers_active` (`is_active`),
  KEY `idx_referral_tiers_required` (`required_referrals`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referral_campaign_participants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `selected_tier_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_referral_participant_customer` (`customer_id`),
  KEY `idx_referral_participant_status` (`status`),
  KEY `idx_referral_participant_tier` (`selected_tier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referral_campaign_referrals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `participant_id` bigint(20) unsigned NOT NULL,
  `registrar_customer_id` int(11) NOT NULL,
  `referred_customer_id` int(11) NOT NULL,
  `referred_phone` varchar(40) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_referral_participant_referred` (`participant_id`, `referred_customer_id`),
  UNIQUE KEY `uniq_referral_referred_customer` (`referred_customer_id`),
  KEY `idx_referral_registrar` (`registrar_customer_id`),
  KEY `idx_referral_participant` (`participant_id`),
  KEY `idx_referral_phone` (`referred_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referral_campaign_reward_claims` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `participant_id` bigint(20) unsigned NOT NULL,
  `claim_status` varchar(20) NOT NULL DEFAULT 'requested',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_referral_claim_participant` (`participant_id`),
  KEY `idx_referral_claim_status` (`claim_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed reward tiers (safe to re-run)
INSERT INTO `referral_campaign_reward_tiers` (`required_referrals`, `reward_name`, `is_active`)
SELECT * FROM (
  SELECT 1 AS required_referrals, 'Tecno T302' AS reward_name, 1 AS is_active
  UNION ALL SELECT 2, '4G smart phone', 1
  UNION ALL SELECT 4, 'Oale 64/3', 1
  UNION ALL SELECT 6, 'A07 64/4', 1
) seeded
WHERE NOT EXISTS (
  SELECT 1 FROM `referral_campaign_reward_tiers` t
  WHERE t.required_referrals = seeded.required_referrals AND t.reward_name = seeded.reward_name
);

COMMIT;

