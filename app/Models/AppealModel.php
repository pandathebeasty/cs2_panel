<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Steam;

/**
 * Ban appeals submitted by players for their own punishment (panel-owned).
 * Staff answer them and set the outcome; the player only ever sees their own.
 */
final class AppealModel
{
    private const TABLE = 'panel_appeals';
    private static bool $ensured = false;
    private static bool $columnsEnsured = false;

    public const STATUSES    = ['open', 'approved', 'rejected'];
    public const PUNISHMENTS = ['ban', 'mute', 'gag', 'silence'];

    public static function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;
        try {
            Database::run(
                'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `steamid64` VARCHAR(20) NOT NULL,
                    `player_name` VARCHAR(128) NULL,
                    `ban_id` INT NOT NULL,
                    `ban_admin_name` VARCHAR(128) NULL,
                    `ban_reason` TEXT NULL,
                    `ban_duration` INT NOT NULL DEFAULT 0,
                    `ban_ends` DATETIME NULL,
                    `punishment` VARCHAR(16) NOT NULL DEFAULT \'ban\',
                    `message` TEXT NOT NULL,
                    `status` VARCHAR(16) NOT NULL DEFAULT \'open\',
                    `admin_reply` TEXT NULL,
                    `handled_by` VARCHAR(20) NULL,
                    `handled_name` VARCHAR(128) NULL,
                    `handled_at` DATETIME NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `steamid64` (`steamid64`),
                    KEY `status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
            self::ensureColumns();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] panel_appeals ensure failed: ' . $e->getMessage());
        }
    }

    /** Add appeal-to-ban snapshot fields to installations with the old table. */
    private static function ensureColumns(): void
    {
        if (self::$columnsEnsured) {
            return;
        }
        self::$columnsEnsured = true;
        $definitions = [
            'ban_id' => 'INT NULL',
            'ban_admin_name' => 'VARCHAR(128) NULL',
            'ban_reason' => 'TEXT NULL',
            'ban_duration' => 'INT NOT NULL DEFAULT 0',
            'ban_ends' => 'DATETIME NULL',
        ];
        try {
            $rows = Database::all(
                'SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ?',
                [self::TABLE]
            );
            $columns = array_fill_keys(array_column($rows, 'column_name'), true);
            foreach ($definitions as $column => $definition) {
                if (!isset($columns[$column])) {
                    Database::run("ALTER TABLE `" . self::TABLE . "` ADD COLUMN `{$column}` {$definition}");
                }
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] panel_appeals column update failed: ' . $e->getMessage());
        }
    }

    /**
     * Paginated list. $ownerSteamId limits the result to one player's appeals
     * (that is what a non-staff visitor is allowed to see).
     */
    public static function paginate(array $filters, int $page, int $perPage, ?string $ownerSteamId = null): Paginator
    {
        self::ensureTable();
        $where = [];
        $params = [];

        if ($ownerSteamId !== null) {
            $where[] = 'steamid64 = ?';
            $params[] = $ownerSteamId;
        }
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $steamId = Steam::toSteamId64Any((string) $filters['search']);
            $where[] = '(player_name LIKE ? OR steamid64 LIKE ? OR message LIKE ?'
                . ($steamId !== null ? ' OR steamid64 = ?)' : ')');
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
            if ($steamId !== null) $params[] = $steamId;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        return Paginator::make(
            'SELECT * FROM `' . self::TABLE . '` ' . $whereSql,
            'SELECT COUNT(*) FROM `' . self::TABLE . '` ' . $whereSql,
            $params,
            $page,
            $perPage,
            "FIELD(status, 'open') DESC, id DESC"
        );
    }

    public static function find(int $id): ?array
    {
        self::ensureTable();
        return Database::one('SELECT * FROM `' . self::TABLE . '` WHERE id = ? LIMIT 1', [$id]);
    }

    /** How many appeals a player already has waiting for an answer. */
    public static function openCountFor(string $steamId64): int
    {
        self::ensureTable();
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE steamid64 = ? AND status = \'open\'',
            [$steamId64]
        );
    }

    /**
     * Number of appeals still waiting for staff (for the nav badge). Runs on
     * every page, so it never creates the table — Schema::sync() owns that.
     */
    public static function openCount(): int
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        try {
            return $memo = (int) Database::scalar('SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE status = \'open\'');
        } catch (\Throwable $e) {
            return $memo = 0;
        }
    }

    public static function create(string $steamId64, string $name, array $ban, string $message): int
    {
        self::ensureTable();
        Database::run(
            'INSERT INTO `' . self::TABLE . '`
                (steamid64, player_name, ban_id, ban_admin_name, ban_reason, ban_duration, ban_ends, punishment, message)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'ban\', ?)',
            [
                $steamId64,
                mb_substr($name, 0, 128) ?: null,
                (int) $ban['id'],
                mb_substr((string) ($ban['admin_name'] ?? ''), 0, 128) ?: null,
                mb_substr((string) ($ban['reason'] ?? ''), 0, 4000) ?: null,
                max(0, (int) ($ban['duration'] ?? 0)),
                !empty($ban['ends']) ? $ban['ends'] : null,
                mb_substr($message, 0, 4000),
            ]
        );
        return (int) Database::lastInsertId();
    }

    public static function resolve(int $id, string $status, string $reply, string $adminSteamId, string $adminName): void
    {
        self::ensureTable();
        Database::run(
            'UPDATE `' . self::TABLE . '`
             SET status = ?, admin_reply = ?, handled_by = ?, handled_name = ?, handled_at = NOW()
             WHERE id = ?',
            [
                in_array($status, self::STATUSES, true) ? $status : 'open',
                mb_substr($reply, 0, 4000) ?: null,
                $adminSteamId ?: null,
                mb_substr($adminName, 0, 128) ?: null,
                $id,
            ]
        );
    }

    public static function delete(int $id): void
    {
        self::ensureTable();
        Database::run('DELETE FROM `' . self::TABLE . '` WHERE id = ?', [$id]);
    }
}
