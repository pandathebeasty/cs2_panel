-- Mixed packages: a store package can also grant a predefined admin group for a
-- number of days (panel-tracked expiry). These columns record what admin perk an
-- order granted, so it can be revoked on refund or when it expires.
-- The app self-heals these columns at runtime (OrderModel::ensureColumns).

ALTER TABLE `panel_orders` ADD COLUMN `admin_group_id` INT NULL;
ALTER TABLE `panel_orders` ADD COLUMN `admin_group_name` VARCHAR(64) NULL;
ALTER TABLE `panel_orders` ADD COLUMN `admin_immunity` INT NOT NULL DEFAULT 0;
ALTER TABLE `panel_orders` ADD COLUMN `admin_days` INT NOT NULL DEFAULT 0;
ALTER TABLE `panel_orders` ADD COLUMN `admin_expires_at` DATETIME NULL;
ALTER TABLE `panel_orders` ADD COLUMN `admin_granted` TINYINT(1) NOT NULL DEFAULT 0;
