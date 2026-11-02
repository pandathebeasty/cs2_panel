-- Store RCON passwords in a panel-owned table instead of relying on a column
-- in the plugin's sa_servers (which CS2-SimpleAdmin owns and does not provide),
-- so the password actually persists. The app self-heals this table at runtime
-- (ServerModel::ensureRconTable), so a manual upload works without this migration.

CREATE TABLE IF NOT EXISTS `panel_server_rcon` (
    `server_id` INT NOT NULL,
    `rcon_password` VARCHAR(191) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
