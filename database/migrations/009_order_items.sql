-- Cart support: an order can contain multiple items, each with its own
-- beneficiary (own account or a gift SteamID) and its own VIP/admin perk.
-- The app self-heals this table at runtime (OrderModel::ensureItemsTable), so a
-- manual upload works without running this migration. Single-item orders keep
-- working via the columns on panel_orders; multi-item orders use these rows.

CREATE TABLE IF NOT EXISTS `panel_order_items` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `order_id` INT NOT NULL,
    `beneficiary_steamid` VARCHAR(32) NOT NULL,
    `beneficiary_name` VARCHAR(128) NULL,
    `package_id` VARCHAR(64) NOT NULL,
    `package_name` VARCHAR(128) NOT NULL,
    `vip_group` VARCHAR(64) NULL,
    `vip_days` INT NOT NULL DEFAULT 0,
    `admin_group_id` INT NULL,
    `admin_group_name` VARCHAR(64) NULL,
    `admin_immunity` INT NOT NULL DEFAULT 0,
    `admin_days` INT NOT NULL DEFAULT 0,
    `admin_expires_at` DATETIME NULL,
    `admin_granted` TINYINT(1) NOT NULL DEFAULT 0,
    `vip_granted` TINYINT(1) NOT NULL DEFAULT 0,
    `server_id` VARCHAR(16) NOT NULL DEFAULT 'all',
    `list_net_cents` INT NOT NULL DEFAULT 0,
    `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
    `net_cents` INT NOT NULL DEFAULT 0,
    `fee_cents` INT NOT NULL DEFAULT 0,
    `total_cents` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order` (`order_id`),
    KEY `idx_admin_exp` (`admin_granted`, `admin_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
