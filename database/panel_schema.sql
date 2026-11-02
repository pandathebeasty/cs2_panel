-- Panel's own schema (separate from the SimpleAdmin sa_* tables).
-- Run automatically by the setup wizard, but you can also run it manually.

-- Users with access to the PANEL (distinct from in-game admins in sa_admins).
CREATE TABLE IF NOT EXISTS `panel_users` (
    `id`         INT NOT NULL AUTO_INCREMENT,
    `steamid64`  BIGINT NOT NULL,
    `name`       VARCHAR(128) NOT NULL DEFAULT 'Moderator',
    -- Two active roles: owner (full access) and moderator (bans/mutes + view).
    -- Legacy values (root/admin) are kept in the ENUM so existing rows stay
    -- valid and are normalized in code.
    `role`       ENUM('owner','moderator','root','admin') NOT NULL DEFAULT 'moderator',
    `can_manage` TINYINT(1) NOT NULL DEFAULT 0,
    `added_by`   BIGINT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_steamid` (`steamid64`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Log of actions performed from the panel.
CREATE TABLE IF NOT EXISTS `panel_logs` (
    `id`         INT NOT NULL AUTO_INCREMENT,
    `actor_steamid` VARCHAR(32) NULL,
    `actor_name` VARCHAR(128) NULL,
    `action`     VARCHAR(64) NOT NULL,
    `target`     VARCHAR(191) NULL,
    `details`    VARCHAR(255) NULL,
    `ip`         VARCHAR(45) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- UI-editable settings for root (overrides on top of config.php).
CREATE TABLE IF NOT EXISTS `panel_settings` (
    `key`        VARCHAR(64) NOT NULL,
    `value`      TEXT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Rank-based contests (award VIP to top players; announced on a separate
-- Discord webhook). One row per contest.
CREATE TABLE IF NOT EXISTS `panel_contests` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(128) NOT NULL,
    `vip_group`     VARCHAR(64) NOT NULL,            -- default prize group
    `vip_days`      INT NOT NULL DEFAULT 0,          -- 0 = permanent (default)
    `prizes`        TEXT NULL,                       -- JSON: position -> {group, days}
    `metric`        VARCHAR(16) NOT NULL DEFAULT 'rank', -- winner selection: rank | playtime
    `max_winners`   INT NOT NULL DEFAULT 1,
    `mode`          ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    `reset_stats`   TINYINT(1) NOT NULL DEFAULT 0,   -- reset all stats after award
    `reset_at_start` TINYINT(1) NOT NULL DEFAULT 0,  -- reset all stats when it starts
    `status`        VARCHAR(16) NOT NULL DEFAULT 'active', -- scheduled | active | finished
    `start_at`      DATETIME NULL,                   -- optional scheduled start
    `started`       TINYINT(1) NOT NULL DEFAULT 0,
    `ends_at`       DATETIME NOT NULL,               -- when winners are drawn/awarded
    `awarded_at`    DATETIME NULL,                   -- when winners were actually processed
    `stats_reset_done` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by`    BIGINT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_ends` (`ends_at`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The players selected as winners of a contest (top-N by rank points at draw
-- time). In auto mode VIP is granted; in manual mode this is just a record.
CREATE TABLE IF NOT EXISTS `panel_contest_winners` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `contest_id`  INT NOT NULL,
    `position`    INT NOT NULL DEFAULT 0,
    `steamid`     VARCHAR(32) NOT NULL,
    `name`        VARCHAR(128) NULL,
    `points`      BIGINT NOT NULL DEFAULT 0,
    `vip_group`   VARCHAR(64) NULL,                  -- prize for this position
    `vip_days`    INT NOT NULL DEFAULT 0,
    `vip_granted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_contest` (`contest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default values for settings.
INSERT IGNORE INTO `panel_settings` (`key`, `value`) VALUES
    ('site_name',       'CS2 Panel'),
    ('theme_accent',    '#e01e37'),
    ('theme_name',      'default'),
    ('theme_mode',      'system'),
    ('custom_themes',   ''),
    ('vip_enabled',     '1'),
    ('zenith_enabled',  '1'),
    ('contests_enabled', '1'),
    ('contest_webhook',  ''),
    ('contest_retention_days', '90'),
    ('contest_autoadd_days',   '30'),
    ('contest_cron_token', ''),
    ('per_page',        '25'),
    ('discord_webhook', ''),
    ('discord_enabled', '0'),
    ('discord_log_all', '1'),
    ('discord_notify_bans', '1'),
    ('discord_notify_mutes', '1'),
    ('discord_notify_warns', '1'),
    ('discord_notify_vip', '1'),
    ('discord_notify_admins', '1'),
    ('discord_notify_resets', '1'),
    ('discord_notify_contests', '1'),
    ('discord_invite',  ''),
    ('forum_url',       ''),
    ('website_url',     ''),
    ('logo_path',       ''),
    ('favicon_path',    '');
