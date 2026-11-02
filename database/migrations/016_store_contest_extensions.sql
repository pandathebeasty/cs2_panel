-- Existing values are preserved when these columns already exist.
-- Migrator ignores duplicate-column errors when resuming a partial upgrade.

ALTER TABLE `panel_orders` ADD COLUMN `discount_code` VARCHAR(48) NULL;
ALTER TABLE `panel_orders` ADD COLUMN `credit_amount` INT NOT NULL DEFAULT 0;
ALTER TABLE `panel_orders` ADD COLUMN `credits_granted` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `panel_order_items` ADD COLUMN `credit_amount` INT NOT NULL DEFAULT 0;
ALTER TABLE `panel_order_items` ADD COLUMN `credits_granted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `panel_contests` ADD COLUMN `playtime_source` VARCHAR(8) NOT NULL DEFAULT 'all';
ALTER TABLE `panel_contests` ADD COLUMN `extend_expiring` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `panel_contests` ADD COLUMN `credit_amount` INT NOT NULL DEFAULT 0;
ALTER TABLE `panel_contest_winners` ADD COLUMN `credit_amount` INT NOT NULL DEFAULT 0;
ALTER TABLE `panel_contest_winners` ADD COLUMN `credits_granted` TINYINT(1) NOT NULL DEFAULT 0;

-- Credits, donations and admin-only purchases do not have a VIP group.
-- Keep any existing wider column type rather than truncating stored groups.
SET @panel_vip_group_type = (
    SELECT `COLUMN_TYPE` FROM information_schema.columns
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'panel_orders' AND `COLUMN_NAME` = 'vip_group'
);
SET @panel_vip_group_ddl = CONCAT('ALTER TABLE `panel_orders` MODIFY `vip_group` ', @panel_vip_group_type, ' NULL');
PREPARE panel_vip_group_statement FROM @panel_vip_group_ddl;
EXECUTE panel_vip_group_statement;
DEALLOCATE PREPARE panel_vip_group_statement;
