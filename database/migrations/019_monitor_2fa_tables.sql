-- Server monitoring and staff 2FA tables (also created at runtime by their models).

CREATE TABLE IF NOT EXISTS `panel_server_samples` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `server_id` INT NOT NULL,
    `sampled_at` INT UNSIGNED NOT NULL,
    `online` TINYINT(1) NOT NULL DEFAULT 0,
    `players` SMALLINT UNSIGNED NULL,
    `max_players` SMALLINT UNSIGNED NULL,
    `map` VARCHAR(64) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_server_time` (`server_id`, `sampled_at`),
    KEY `idx_time` (`sampled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `panel_server_monitor` (
    `server_id` INT NOT NULL,
    `status` VARCHAR(8) NOT NULL DEFAULT 'up',
    `fail_count` INT NOT NULL DEFAULT 0,
    `changed_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `panel_2fa` (
    `steamid` VARCHAR(32) NOT NULL,
    `secret` VARCHAR(64) NOT NULL,
    `recovery` TEXT NULL,
    `last_step` BIGINT NOT NULL DEFAULT 0,
    `failed` INT NOT NULL DEFAULT 0,
    `locked_until` INT UNSIGNED NOT NULL DEFAULT 0,
    `enabled_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`steamid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
