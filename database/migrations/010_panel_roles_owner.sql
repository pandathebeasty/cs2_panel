-- Permission model simplified to two panel-only roles: owner + moderator.
-- Widen the role ENUM to include the new values (keep legacy ones so the
-- conversion UPDATEs below are valid), then convert existing rows:
--   root  -> owner
--   admin -> moderator
-- can_manage is derived from the role (owner = 1). Re-running is harmless.

ALTER TABLE `panel_users` MODIFY COLUMN `role` ENUM('owner','moderator','root','admin') NOT NULL DEFAULT 'moderator';
UPDATE `panel_users` SET `role` = 'owner' WHERE `role` = 'root';
UPDATE `panel_users` SET `role` = 'moderator' WHERE `role` = 'admin';
UPDATE `panel_users` SET `can_manage` = 1 WHERE `role` = 'owner';
UPDATE `panel_users` SET `can_manage` = 0 WHERE `role` = 'moderator';
