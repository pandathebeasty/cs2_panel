-- Tables previously created only by runtime model fallbacks.

CREATE TABLE IF NOT EXISTS `panel_activity` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `steamid` VARCHAR(32) NOT NULL,
    `seen_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_seen` (`seen_at`),
    KEY `idx_steam_seen` (`steamid`, `seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `panel_benefits` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category` VARCHAR(64) NOT NULL DEFAULT '',
    `label` VARCHAR(128) NULL,
    `description` TEXT NULL,
    `icon` VARCHAR(191) NOT NULL DEFAULT '',
    `sort_order` INT NOT NULL DEFAULT 0,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_category` (`category`),
    KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `panel_vip_pending` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `steamid` VARCHAR(32) NOT NULL,
    `name` VARCHAR(128) NULL,
    `sid` INT NOT NULL,
    `group` VARCHAR(64) NOT NULL,
    `days` INT NOT NULL DEFAULT 0,
    `contest_id` INT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_player_server` (`steamid`, `sid`),
    KEY `idx_steam` (`steamid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
