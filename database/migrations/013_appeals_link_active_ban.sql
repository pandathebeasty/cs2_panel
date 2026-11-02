-- Tie every new appeal to the active ban it challenges and keep a detail snapshot.
CREATE TABLE IF NOT EXISTS `panel_appeals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `steamid64` VARCHAR(20) NOT NULL,
    `player_name` VARCHAR(128) NULL,
    `ban_id` INT NULL,
    `ban_admin_name` VARCHAR(128) NULL,
    `ban_reason` TEXT NULL,
    `ban_duration` INT NOT NULL DEFAULT 0,
    `ban_ends` DATETIME NULL,
    `punishment` VARCHAR(16) NOT NULL DEFAULT 'ban',
    `message` TEXT NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'open',
    `admin_reply` TEXT NULL,
    `handled_by` VARCHAR(20) NULL,
    `handled_name` VARCHAR(128) NULL,
    `handled_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `steamid64` (`steamid64`),
    KEY `status` (`status`),
    KEY `ban_id` (`ban_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `panel_appeals` ADD COLUMN `ban_id` INT NULL;
ALTER TABLE `panel_appeals` ADD COLUMN `ban_admin_name` VARCHAR(128) NULL;
ALTER TABLE `panel_appeals` ADD COLUMN `ban_reason` TEXT NULL;
ALTER TABLE `panel_appeals` ADD COLUMN `ban_duration` INT NOT NULL DEFAULT 0;
ALTER TABLE `panel_appeals` ADD COLUMN `ban_ends` DATETIME NULL;