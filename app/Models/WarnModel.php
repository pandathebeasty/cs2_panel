<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Steam;

/**
 * Acces la sa_warns.
 */
final class WarnModel
{
    private static function p(): string
    {
        return Config::get('sa_prefix', 'sa_');
    }

    public static function paginate(array $filters, int $page, int $perPage): Paginator
    {
        $p = self::p();
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $steamId = Steam::toSteamId64Any((string) $filters['search']);
            $where[] = '(w.player_name LIKE ? OR w.player_steamid LIKE ?'
                . ($steamId !== null ? ' OR w.player_steamid = ?)' : ')');
            $like = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            if ($steamId !== null) $params[] = $steamId;
        }
        if (!empty($filters['status'])) {
            $where[] = 'w.status = ?';
            $params[] = $filters['status'];
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $base = "SELECT w.*, s.hostname AS server_name
                 FROM {$p}warns w
                 LEFT JOIN {$p}servers s ON s.id = w.server_id
                 {$whereSql}";
        $count = "SELECT COUNT(*) FROM {$p}warns w {$whereSql}";

        return Paginator::make($base, $count, $params, $page, $perPage, 'w.id DESC');
    }

    public static function create(array $data): int
    {
        $p = self::p();
        $duration = (int) $data['duration'];
        $ends = date('Y-m-d H:i:s', time() + max(0, $duration) * 60);

        Database::run(
            "INSERT INTO {$p}warns
             (player_name, player_steamid, admin_steamid, admin_name, reason, duration, ends, server_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')",
            [
                $data['player_name'] ?? null,
                $data['player_steamid'],
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

    /** Fetch a single warning row (for notifications / detail). */
    public static function find(int $id): ?array
    {
        $p = self::p();
        return Database::one("SELECT * FROM {$p}warns WHERE id = ? LIMIT 1", [$id]);
    }

    public static function expire(int $id): void
    {
        $p = self::p();
        Database::run("UPDATE {$p}warns SET status = 'EXPIRED' WHERE id = ?", [$id]);
    }

    /** Delete a single warning row (destructive). */
    public static function delete(int $id): void
    {
        $p = self::p();
        Database::run("DELETE FROM {$p}warns WHERE id = ?", [$id]);
    }

    public static function stats(): array
    {
        $p = self::p();
        return [
            'active' => (int) Database::scalar("SELECT COUNT(*) FROM {$p}warns WHERE status = 'ACTIVE'"),
        ];
    }
}
