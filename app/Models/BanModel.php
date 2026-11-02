<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Steam;

/**
 * Access to sa_bans / sa_unbans.
 */
final class BanModel
{
    private static function p(): string
    {
        return Config::get('sa_prefix', 'sa_');
    }

    /**
     * Paginated list with optional filters.
     * @param array{search?:string,status?:string,server_id?:int} $filters
     */
    public static function paginate(array $filters, int $page, int $perPage): Paginator
    {
        $p = self::p();
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $steamId = Steam::toSteamId64Any((string) $filters['search']);
            $where[] = '(b.player_name LIKE ? OR b.player_steamid LIKE ? OR b.player_ip LIKE ?'
                . ($steamId !== null ? ' OR b.player_steamid = ?)' : ')');
            $like = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            if ($steamId !== null) $params[] = $steamId;
        }
        if (!empty($filters['status'])) {
            $where[] = 'b.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['server_id'])) {
            $where[] = 'b.server_id = ?';
            $params[] = (int) $filters['server_id'];
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $base = "SELECT b.*, s.hostname AS server_name
                 FROM {$p}bans b
                 LEFT JOIN {$p}servers s ON s.id = b.server_id
                 {$whereSql}";
        $count = "SELECT COUNT(*) FROM {$p}bans b {$whereSql}";

        return Paginator::make($base, $count, $params, $page, $perPage, 'b.id DESC');
    }

    public static function find(int $id): ?array
    {
        $p = self::p();
        return Database::one(
            "SELECT b.*, s.hostname AS server_name
             FROM {$p}bans b
             LEFT JOIN {$p}servers s ON s.id = b.server_id
             WHERE b.id = ? LIMIT 1",
            [$id]
        );
    }

    /**
     * Active, unexpired bans held by one player, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function activeForSteamId(string $steamId64): array
    {
        $p = self::p();
        return Database::all(
            "SELECT b.*, s.hostname AS server_name
             FROM {$p}bans b
             LEFT JOIN {$p}servers s ON s.id = b.server_id
             WHERE b.player_steamid = ? AND UPPER(b.status) = 'ACTIVE'
               AND (b.duration = 0 OR b.ends IS NULL OR b.ends > NOW())
             ORDER BY b.created DESC, b.id DESC",
            [$steamId64]
        );
    }

    /** Return a ban only when it is active, unexpired and belongs to a player. */
    public static function activeForSteamIdById(int $banId, string $steamId64): ?array
    {
        $p = self::p();
        return Database::one(
            "SELECT b.*, s.hostname AS server_name
             FROM {$p}bans b
             LEFT JOIN {$p}servers s ON s.id = b.server_id
             WHERE b.id = ? AND b.player_steamid = ? AND UPPER(b.status) = 'ACTIVE'
               AND (b.duration = 0 OR b.ends IS NULL OR b.ends > NOW())
             LIMIT 1",
            [$banId, $steamId64]
        );
    }

    /**
     * Create a ban. duration in minutes (0 = permanent).
     */
    public static function create(array $data): int
    {
        $p = self::p();
        $duration = (int) $data['duration'];
        $ends = $duration > 0 ? date('Y-m-d H:i:s', time() + $duration * 60) : null;

        Database::run(
            "INSERT INTO {$p}bans
             (player_name, player_steamid, player_ip, admin_steamid, admin_name, reason, duration, ends, server_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')",
            [
                $data['player_name'] ?? null,
                $data['player_steamid'] ?? null,
                $data['player_ip'] ?? null,
                $data['admin_steamid'],
                $data['admin_name'],
                $data['reason'],
                $duration,
                $ends,
                !empty($data['server_id']) ? (int) $data['server_id'] : null,
            ]
        );
        return (int) Database::lastInsertId();
    }

    /**
     * Edit an existing ban. Every editable field can be changed:
     * player_name, player_steamid, player_ip, reason, duration (minutes,
     * 0 = permanent), server_id and status (ACTIVE/UNBANNED). `ends` is recomputed from
     * the ban's original creation time so editing the duration doesn't silently
     * shift the start. An already lifted ban keeps its recorded unban time, while
     * changing ACTIVE to UNBANNED records the complete unban.
     *
     * @param array{
     *   player_name?:?string, player_steamid?:?string, player_ip?:?string,
     *   reason?:string, duration?:int, status?:string, server_id?:?int
     * } $data
     */
    public static function update(int $id, array $data, ?int $liftAdminId = null, string $liftReason = ''): void
    {
        $p = self::p();
        Database::transaction(static function () use ($p, $id, $data, $liftAdminId, $liftReason): void {
            $current = Database::one("SELECT * FROM {$p}bans WHERE id = ? LIMIT 1 FOR UPDATE", [$id]);
            if (!$current) {
                return;
            }

            $duration = (int) ($data['duration'] ?? (int) $current['duration']);

            // Base the new expiry on the original created time when available.
            $createdTs = !empty($current['created']) ? strtotime((string) $current['created']) : time();
            if ($createdTs === false) {
                $createdTs = time();
            }
            $ends = $duration > 0 ? date('Y-m-d H:i:s', $createdTs + $duration * 60) : null;

            // Only ACTIVE / UNBANNED are valid statuses; anything else keeps current.
            $currentStatus = strtoupper((string) $current['status']);
            $status = strtoupper(trim((string) ($data['status'] ?? $currentStatus)));
            if (!in_array($status, ['ACTIVE', 'UNBANNED'], true)) {
                $status = $currentStatus;
            }
            $liftNow = $status === 'UNBANNED' && $currentStatus !== 'UNBANNED';
            if ($status === 'UNBANNED' && !$liftNow) {
                $ends = $current['ends'];
            }

            $name    = array_key_exists('player_name', $data) ? ($data['player_name'] !== '' ? $data['player_name'] : null) : $current['player_name'];
            $steamid = array_key_exists('player_steamid', $data) ? ($data['player_steamid'] !== '' ? $data['player_steamid'] : null) : $current['player_steamid'];
            $ip      = array_key_exists('player_ip', $data) ? ($data['player_ip'] !== '' ? $data['player_ip'] : null) : $current['player_ip'];
            $reason  = array_key_exists('reason', $data) ? (trim((string) $data['reason']) !== '' ? trim((string) $data['reason']) : 'No reason') : $current['reason'];
            $serverId = array_key_exists('server_id', $data) ? ServerModel::validatedSelection($data['server_id']) : ($current['server_id'] ?? null);
            $ends = array_key_exists('ends', $data) ? ($data['ends'] !== '' ? $data['ends'] : null) : $ends;

            Database::run(
                "UPDATE {$p}bans
                    SET player_name = ?, player_steamid = ?, player_ip = ?,
                        reason = ?, duration = ?, ends = ?, status = ?, server_id = ?
                  WHERE id = ?",
                [$name, $steamid, $ip, $reason, $duration, $ends, $status, $serverId, $id]
            );
            if ($liftNow) {
                if ($liftAdminId === null) {
                    throw new \LogicException('An unban made through ban editing requires the acting administrator.');
                }
                self::recordUnban($id, $liftAdminId, $liftReason);
            }
        });
    }

    /**
     * Delete a single ban (and its related unban row, if any). Destructive.
     */
    public static function delete(int $id): void
    {
        $p = self::p();
        $ban = self::find($id);
        if ($ban && !empty($ban['unban_id'])) {
            Database::run("DELETE FROM {$p}unbans WHERE id = ?", [(int) $ban['unban_id']]);
        }
        Database::run("DELETE FROM {$p}bans WHERE id = ?", [$id]);
    }

    /**
     * Delete ALL bans (and the related unban rows). Destructive, root-only.
     * Returns the number of ban rows removed.
     */
    public static function deleteAll(): int
    {
        $p = self::p();
        $count = (int) Database::scalar("SELECT COUNT(*) FROM {$p}bans");
                                                                               
        Database::run("DELETE FROM {$p}unbans");
        Database::run("DELETE FROM {$p}bans");
        return $count;
    }

    /**
     * Unban: create a row in sa_unbans, mark the ban as UNBANNED and end it now.
     */
    public static function unban(int $banId, int $adminId, string $reason): void
    {
        Database::transaction(static fn() => self::recordUnban($banId, $adminId, $reason));
    }

    private static function recordUnban(int $banId, int $adminId, string $reason): void
    {
        $p = self::p();
        Database::run(
            "INSERT INTO {$p}unbans (ban_id, admin_id, reason) VALUES (?, ?, ?)",
            [$banId, $adminId, $reason !== '' ? $reason : 'Unknown']
        );
        $unbanId = (int) Database::lastInsertId();
        Database::run(
            "UPDATE {$p}bans SET status = 'UNBANNED', unban_id = ?, ends = ? WHERE id = ?",
            [$unbanId, date('Y-m-d H:i:s'), $banId]
        );
    }
    
    /** Statistics for the dashboard. */
    public static function stats(): array
    {
        $p = self::p();
        return [
            'total'  => (int) Database::scalar("SELECT COUNT(*) FROM {$p}bans"),
            'active' => (int) Database::scalar("SELECT COUNT(*) FROM {$p}bans WHERE status = 'ACTIVE'"),
            'today'  => (int) Database::scalar("SELECT COUNT(*) FROM {$p}bans WHERE DATE(created) = CURDATE()"),
        ];
    }
}
