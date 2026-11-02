-- Contests feature: rank-based VIP contests announced on a separate Discord
-- webhook. Adds two tables and the related default settings.

CREATE TABLE IF NOT EXISTS `panel_contests` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(128) NOT NULL,
    `vip_group`     VARCHAR(64) NOT NULL,
    `vip_days`      INT NOT NULL DEFAULT 0,
    `max_winners`   INT NOT NULL DEFAULT 1,
    `mode`          ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    `reset_stats`   TINYINT(1) NOT NULL DEFAULT 0,
    `status`        ENUM('active','finished') NOT NULL DEFAULT 'active',
    `ends_at`       DATETIME NOT NULL,
    `awarded_at`    DATETIME NULL,
    `stats_reset_done` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by`    BIGINT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_ends` (`ends_at`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `panel_contest_winners` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `contest_id`  INT NOT NULL,
    `position`    INT NOT NULL DEFAULT 0,
    `steamid`     VARCHAR(32) NOT NULL,
    `name`        VARCHAR(128) NULL,
    `points`      BIGINT NOT NULL DEFAULT 0,
    `vip_granted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_contest` (`contest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `panel_settings` (`key`, `value`) VALUES ('contests_enabled', '1');
INSERT IGNORE INTO `panel_settings` (`key`, `value`) VALUES ('contest_webhook', '');
INSERT IGNORE INTO `panel_settings` (`key`, `value`) VALUES ('contest_retention_days', '90');
INSERT IGNORE INTO `panel_settings` (`key`, `value`) VALUES ('contest_autoadd_days', '30');
INSERT IGNORE INTO `panel_settings` (`key`, `value`) VALUES ('contest_cron_token', '');
