-- Store / shop: sell VIP packages via PayPal.
-- The app self-heals this table at runtime (OrderModel::ensureTables), so a
-- manual upload works even without running this migration. Store settings are
-- plain key/value rows in panel_settings, seeded below.

CREATE TABLE IF NOT EXISTS `panel_orders` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(24) NOT NULL,
    `buyer_steamid` VARCHAR(32) NOT NULL,
    `buyer_name` VARCHAR(128) NULL,
    `beneficiary_steamid` VARCHAR(32) NOT NULL,
    `beneficiary_name` VARCHAR(128) NULL,
    `package_id` VARCHAR(64) NOT NULL,
    `package_name` VARCHAR(128) NOT NULL,
    `vip_group` VARCHAR(64) NOT NULL,
    `vip_days` INT NOT NULL DEFAULT 0,
    `server_id` VARCHAR(16) NOT NULL DEFAULT 'all',
    `currency` VARCHAR(8) NOT NULL DEFAULT 'EUR',
    `net_cents` INT NOT NULL DEFAULT 0,
    `fee_cents` INT NOT NULL DEFAULT 0,
    `total_cents` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
    `provider` VARCHAR(16) NOT NULL DEFAULT 'paypal',
    `provider_order_id` VARCHAR(64) NULL,
    `provider_capture_id` VARCHAR(64) NULL,
    `vip_granted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `paid_at` DATETIME NULL,
    `refunded_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_code` (`code`),
    KEY `idx_buyer` (`buyer_steamid`),
    KEY `idx_status` (`status`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default Store settings (only inserted if absent).
INSERT IGNORE INTO `panel_settings` (`key`, `value`) VALUES
    ('store_enabled', '0'),
    ('store_currency', 'EUR'),
    ('store_paypal_mode', 'sandbox'),
    ('store_paypal_client_id', ''),
    ('store_paypal_secret', ''),
    ('store_paypal_webhook_id', ''),
    ('store_fee_percent', '3.4'),
    ('store_fee_fixed', '0.35'),
    ('store_pass_fee_to_buyer', '1'),
    ('store_packages', '[]');
