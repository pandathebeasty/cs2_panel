-- Store: record the discount applied to an order (for history/receipts) and an
-- optional buyer email for the confirmation message. The app self-heals these
-- columns at runtime (OrderModel::ensureColumns), so a manual upload works too.

ALTER TABLE `panel_orders` ADD COLUMN `list_net_cents` INT NOT NULL DEFAULT 0;
ALTER TABLE `panel_orders` ADD COLUMN `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0;
ALTER TABLE `panel_orders` ADD COLUMN `buyer_email` VARCHAR(190) NULL;
