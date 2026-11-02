-- Store each order's code on its item rows too, so panel_order_items is
-- self-explanatory when viewed directly in the DB. The app self-heals this
-- column at runtime (OrderModel::ensureItemsTable), so a manual upload works
-- without running this migration. Backfill fills existing rows from the parent.

ALTER TABLE `panel_order_items` ADD COLUMN IF NOT EXISTS `order_code` VARCHAR(32) NULL AFTER `order_id`;
UPDATE `panel_order_items` i JOIN `panel_orders` o ON o.id = i.order_id SET i.order_code = o.code WHERE i.order_code IS NULL OR i.order_code = '';
