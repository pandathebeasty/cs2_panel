-- Contest scheduling: optional start time + reset-at-start + 'scheduled' status.
-- The app self-heals these columns at runtime (ContestModel::ensureColumns),
-- so a manual upload works without running this migration.

ALTER TABLE `panel_contests` ADD COLUMN `start_at` DATETIME NULL;
ALTER TABLE `panel_contests` ADD COLUMN `started` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `panel_contests` ADD COLUMN `reset_at_start` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `panel_contests` MODIFY `status` VARCHAR(16) NOT NULL DEFAULT 'active';
