<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Steam;

/**
 * Acces la sa_mutes / sa_unmutes. type: GAG | MUTE | SILENCE.
 */
final class MuteModel
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
            $where[] = '(m.player_name LIKE ? OR m.player_steamid LIKE ?'
                . ($steamId !== null ? ' OR m.player_steamid = ?)' : ')');
            $like = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            if ($steamId !== null) $params[] = $steamId;
        }
        if (!empty($filters['type'])) {
            $where[] = 'm.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'm.status = ?';
            $params[] = $filters['status'];
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $base = "SELECT m.*, s.hostname AS server_name
                 FROM {$p}mutes m
                 LEFT JOIN {$p}servers s ON s.id = m.server_id
                 {$whereSql}";
        $count = "SELECT COUNT(*) FROM {$p}mutes m {$whereSql}";

        return Paginator::make($base, $count, $params, $page, $perPage, 'm.id DESC');
    }

    public static function create(array $data): int
    {
        $p = self::p();
        $duration = (int) $data['duration'];
        $ends = $duration > 0 ? date('Y-m-d H:i:s', time() + $duration * 60) : null;
        $type = in_array($data['type'], ['GAG', 'MUTE', 'SILENCE'], true) ? $data['type'] : 'GAG';

        Database::run(
            "INSERT INTO {$p}mutes
             (player_name, player_steamid, admin_steamid, admin_name, reason, duration, ends, type, server_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')",
            [
                $data['player_name'] ?? null,
                $data['player_steamid'],
                $data['admin_steamid'],
                $data['admin_name'],
                $data['reason'],
                $duration,
                $ends,
                $type,
                !empty($data['server_id']) ? (int) $data['server_id'] : null,
            ]
        );
        return (int) Database::lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $p = self::p();
        return Database::one("SELECT * FROM {$p}mutes WHERE id = ? LIMIT 1", [$id]);
    }

    /**
     * Delete a single mute/gag/silence (and its related unmute row, if any).
     */
    public static function delete(int $id): void
    {
        $p = self::p();
        $mute = self::find($id);
        if ($mute && !empty($mute['unmute_id'])) {
            Database::run("DELETE FROM {$p}unmutes WHERE id = ?", [(int) $mute['unmute_id']]);
        }
        Database::run("DELETE FROM {$p}mutes WHERE id = ?", [$id]);
    }

    public static function unmute(int $muteId, int $adminId, string $reason): void
    {
        $p = self::p();
        Database::transaction(static function () use ($p, $muteId, $adminId, $reason): void {
            Database::run(
                "INSERT INTO {$p}unmutes (mute_id, admin_id, reason) VALUES (?, ?, ?)",
                [$muteId, $adminId, $reason !== '' ? $reason : 'Unknown']
            );
            $unmuteId = (int) Database::lastInsertId();
            Database::run(
                "UPDATE {$p}mutes SET status = 'UNMUTED', unmute_id = ?, ends = ? WHERE id = ?",
                [$unmuteId, date('Y-m-d H:i:s'), $muteId]
            );
        }, 'simpleadmin');
    }

    public static function stats(): array
    {
        $p = self::p();
        return [
            'active' => (int) Database::scalar("SELECT COUNT(*) FROM {$p}mutes WHERE status = 'ACTIVE'"),
        ];
    }
}
