<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

/**
 * Access to sa_servers (including rcon_password).
 */
final class ServerModel
{
    /** Panel-owned table that stores RCON passwords (keyed by sa_servers.id). */
    private const RCON_TABLE = 'panel_server_rcon';
    private static bool $rconEnsured = false;

    private static function p(): string
    {
        return Config::get('sa_prefix', 'sa_');
    }

    /**
     * The RCON password lives in a panel-owned table, NOT in sa_servers — that
     * table is managed by the CS2-SimpleAdmin plugin and has no rcon_password
     * column, so writing there never persisted. Create the table on the fly so
     * a fresh upload works without running the migration.
     */
    public static function ensureRconTable(): void
    {
        if (self::$rconEnsured) {
            return;
        }
        self::$rconEnsured = true;
        if (\App\Core\DevMode::enabled() && (string) Config::get('db.name', '') === '') {
            return;
        }
        try {
            Database::run(
                'CREATE TABLE IF NOT EXISTS `' . self::RCON_TABLE . '` (
                    `server_id` INT NOT NULL,
                    `rcon_password` VARCHAR(191) NULL,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`server_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] ensureRconTable failed: ' . $e->getMessage());
        }
    }

    /** Store (or clear) the RCON password for a server in the panel table. */
    private static function setRconPassword(int $serverId, ?string $password): void
    {
        self::ensureRconTable();
        try {
            if ($password === null || $password === '') {
                Database::run('DELETE FROM `' . self::RCON_TABLE . '` WHERE server_id = ?', [$serverId]);
                return;
            }
            Database::run(
                'INSERT INTO `' . self::RCON_TABLE . '` (server_id, rcon_password) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE rcon_password = VALUES(rcon_password)',
                [$serverId, $password]
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] setRconPassword failed: ' . $e->getMessage());
        }
    }

    /** Read the stored RCON password for a server ('' if none). */
    public static function rconPassword(int $serverId): string
    {
        self::ensureRconTable();
        try {
            return (string) (Database::scalar(
                'SELECT rcon_password FROM `' . self::RCON_TABLE . '` WHERE server_id = ?',
                [$serverId]
            ) ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Create a server row. address is "ip:port". */
    public static function create(string $hostname, string $address, string $rconPassword): int
    {
        $p = self::p();
        Database::run(
            "INSERT INTO {$p}servers (hostname, address) VALUES (?, ?)",
            [$hostname, $address]
        );
        $id = (int) Database::lastInsertId();
        self::setRconPassword($id, $rconPassword);
        return $id;
    }

       
                                                                               
       
    public static function update(int $id, string $hostname, string $address, ?string $rconPassword): void
    {
        $p = self::p();
        Database::run(
            "UPDATE {$p}servers SET hostname = ?, address = ? WHERE id = ?",
            [$hostname, $address, $id]
        );
        // Null means "keep the existing password"; anything else replaces it.
        if ($rconPassword !== null) {
            self::setRconPassword($id, $rconPassword);
        }
    }

    public static function delete(int $id): void
    {
        $p = self::p();
        Database::run("DELETE FROM {$p}servers WHERE id = ?", [$id]);
        self::setRconPassword($id, null);
    }

    /** @return array<int, array> */
    public static function all(): array
    {
        $p = self::p();
        $rows = Database::all("SELECT id, hostname, address FROM {$p}servers ORDER BY hostname ASC");
        // Attach the RCON password from the panel table.
        foreach ($rows as &$r) {
            $r['rcon_password'] = self::rconPassword((int) $r['id']);
        }
        unset($r);
        return $rows;
    }

    /** List for dropdowns (without password). */
    public static function options(): array
    {
        $p = self::p();
        return Database::all("SELECT id, hostname, address FROM {$p}servers ORDER BY hostname ASC");
    }

    public static function publicDetails(int $id): ?array
    {
        $p = self::p();
        return Database::one("SELECT id, hostname, address FROM {$p}servers WHERE id = ? LIMIT 1", [$id]);
    }

    public static function validatedSelection(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $id = is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($id === false || self::publicDetails($id) === null) {
            throw new \InvalidArgumentException(\App\Core\Lang::t('sanctions.server_invalid'));
        }
        return $id;
    }

    public static function find(int $id): ?array
    {
        $p = self::p();
        $row = Database::one("SELECT * FROM {$p}servers WHERE id = ? LIMIT 1", [$id]);
        if ($row !== null) {
            // RCON password lives in the panel table, not sa_servers.
            $row['rcon_password'] = self::rconPassword($id);
        }
        return $row;
    }

    public static function count(): int
    {
        $p = self::p();
        return (int) Database::scalar("SELECT COUNT(*) FROM {$p}servers");
    }

    /**
     * Internal id of the current admin from sa_admins (for sa_unbans.admin_id).
     * Console = 0. If not found, return 0.
     */
    public static function adminIdBySteamId(?string $steamId64): int
    {
        if ($steamId64 === null) {
            return 0;
        }
        $p = self::p();
        $row = Database::one(
            "SELECT id FROM {$p}admins WHERE player_steamid = ? ORDER BY id ASC LIMIT 1",
            [$steamId64]
        );
        return $row ? (int) $row['id'] : 0;
    }
}
