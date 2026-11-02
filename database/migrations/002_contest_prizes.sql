-- Per-position prizes for contests.
-- panel_contests.prizes = JSON map of position -> {group, days} (default prize
-- stays in vip_group/vip_days). panel_contest_winners carries the prize each
-- winner actually gets (vip_group/vip_days).
-- These run once via the migrator; the app also self-heals missing columns at
-- runtime (ContestModel::addColumnIfMissing), so a manual upload works too.

ALTER TABLE `panel_contests` ADD COLUMN `prizes` TEXT NULL;
ALTER TABLE `panel_contest_winners` ADD COLUMN `vip_group` VARCHAR(64) NULL;
ALTER TABLE `panel_contest_winners` ADD COLUMN `vip_days` INT NOT NULL DEFAULT 0;
