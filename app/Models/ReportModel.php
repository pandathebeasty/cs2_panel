<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Steam;

/**
 * Player reports filed from the site (panel-owned). Staff see every report,
 * a regular visitor only sees the ones they filed themselves.
 */
final class ReportModel
{
    private const TABLE = 'panel_reports';
    private static bool $ensured = false;

    public const STATUSES = ['open', 'accepted', 'rejected'];
    /** What the reporter is asking for. */
    public const TYPES = ['ban', 'mute', 'gag', 'silence', 'other'];

    public static function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;
        try {
            Database::createTable(
                'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `reporter_steamid` VARCHAR(20) NOT NULL,
                    `reporter_name` VARCHAR(128) NULL,
                    `target_steamid` VARCHAR(20) NOT NULL,
                    `target_name` VARCHAR(128) NULL,
                    `type` VARCHAR(16) NOT NULL DEFAULT \'other\',
                    `server_id` INT NULL,
                    `message` TEXT NOT NULL,
                    `status` VARCHAR(16) NOT NULL DEFAULT \'open\',
                    `admin_reply` TEXT NULL,
                    `handled_by` VARCHAR(20) NULL,
                    `handled_name` VARCHAR(128) NULL,
                    `handled_at` DATETIME NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `reporter` (`reporter_steamid`),
                    KEY `status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] panel_reports ensure failed: ' . $e->getMessage());
        }
    }

    /** $reporterSteamId limits the list to the reports that visitor filed. */
    public static function paginate(array $filters, int $page, int $perPage, ?string $reporterSteamId = null): Paginator
    {
        self::ensureTable();
        $where = [];
        $params = [];

        if ($reporterSteamId !== null) {
            $where[] = 'reporter_steamid = ?';
            $params[] = $reporterSteamId;
        }
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $steamId = Steam::toSteamId64Any((string) $filters['search']);
            $where[] = '(target_name LIKE ? OR target_steamid LIKE ? OR reporter_name LIKE ? OR message LIKE ?'
                . ($steamId !== null ? ' OR target_steamid = ? OR reporter_steamid = ?)' : ')');
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
            if ($steamId !== null) array_push($params, $steamId, $steamId);
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

    /**
     * Reports still waiting for staff (for the nav badge). Runs on every page,
     * so it never creates the table — Schema::sync() owns that.
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

    /** How many reports this visitor filed in the last hour (anti-spam). */
    public static function recentCountFor(string $reporterSteamId): int
    {
        self::ensureTable();
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::TABLE . '`
             WHERE reporter_steamid = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [$reporterSteamId]
        );
    }

    public static function create(array $data): int
    {
        self::ensureTable();
        Database::run(
            'INSERT INTO `' . self::TABLE . '`
                (reporter_steamid, reporter_name, target_steamid, target_name, type, server_id, message)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (string) $data['reporter_steamid'],
                mb_substr((string) ($data['reporter_name'] ?? ''), 0, 128) ?: null,
                (string) $data['target_steamid'],
                mb_substr((string) ($data['target_name'] ?? ''), 0, 128) ?: null,
                in_array((string) ($data['type'] ?? ''), self::TYPES, true) ? (string) $data['type'] : 'other',
                !empty($data['server_id']) ? (int) $data['server_id'] : null,
                mb_substr((string) $data['message'], 0, 4000),
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
