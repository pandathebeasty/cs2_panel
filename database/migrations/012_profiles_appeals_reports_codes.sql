-- Panel-owned tables added in the profile / appeals / reports / discount update.
-- All statements are idempotent so re-running is harmless.

CREATE TABLE IF NOT EXISTS `panel_profiles` (
    `steamid64` VARCHAR(20) NOT NULL,
    `discord_tag` VARCHAR(64) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`steamid64`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `panel_discount_codes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(48) NOT NULL,
    `percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
    `max_uses` INT NOT NULL DEFAULT 0,
    `used` INT NOT NULL DEFAULT 0,
    `starts_at` DATETIME NULL,
    `ends_at` DATETIME NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `panel_appeals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `steamid64` VARCHAR(20) NOT NULL,
    `player_name` VARCHAR(128) NULL,
    `punishment` VARCHAR(16) NOT NULL DEFAULT 'ban',
    `message` TEXT NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'open',
    `admin_reply` TEXT NULL,
    `handled_by` VARCHAR(20) NULL,
    `handled_name` VARCHAR(128) NULL,
    `handled_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `steamid64` (`steamid64`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `panel_reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `reporter_steamid` VARCHAR(20) NOT NULL,
    `reporter_name` VARCHAR(128) NULL,
    `target_steamid` VARCHAR(20) NOT NULL,
    `target_name` VARCHAR(128) NULL,
    `type` VARCHAR(16) NOT NULL DEFAULT 'other',
    `server_id` INT NULL,
    `message` TEXT NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'open',
    `admin_reply` TEXT NULL,
    `handled_by` VARCHAR(20) NULL,
    `handled_name` VARCHAR(128) NULL,
    `handled_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `reporter` (`reporter_steamid`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
