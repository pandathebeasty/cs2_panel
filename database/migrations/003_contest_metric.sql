-- Contest winner-selection metric: 'rank' (rank points) or 'playtime'.
-- The app also self-heals this column at runtime (ContestModel::ensureColumns),
-- so a manual upload works without running this migration.

ALTER TABLE `panel_contests` ADD COLUMN `metric` VARCHAR(16) NOT NULL DEFAULT 'rank';
