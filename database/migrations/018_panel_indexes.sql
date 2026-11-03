-- Indexes for the audit-log filters, player profile and report lookups.

ALTER TABLE `panel_logs` ADD INDEX IF NOT EXISTS `idx_actor` (`actor_steamid`);
ALTER TABLE `panel_logs` ADD INDEX IF NOT EXISTS `idx_target` (`target`);
ALTER TABLE `panel_logs` ADD INDEX IF NOT EXISTS `idx_ip` (`ip`);
ALTER TABLE `panel_reports` ADD INDEX IF NOT EXISTS `idx_target` (`target_steamid`);
ALTER TABLE `panel_reports` ADD INDEX IF NOT EXISTS `idx_reporter_created` (`reporter_steamid`, `created_at`);
ALTER TABLE `panel_appeals` ADD INDEX IF NOT EXISTS `idx_created` (`created_at`);
