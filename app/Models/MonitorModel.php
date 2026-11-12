<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\A2S;
use App\Core\Database;
use App\Core\Discord;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Server monitoring: periodic A2S samples per server (online, players, map),
 * uptime and player history, plus Discord alerts when a server goes down for
 * several samples in a row and when it comes back.
 */
final class MonitorModel
{
    private const SAMPLES = 'panel_server_samples';
    private const STATE = 'panel_server_monitor';

    public static function ensureTable(): void
    {
        Database::createTable(
            'CREATE TABLE IF NOT EXISTS `' . self::SAMPLES . '` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `server_id`   INT NOT NULL,
                `sampled_at`  INT UNSIGNED NOT NULL,
                `online`      TINYINT(1) NOT NULL DEFAULT 0,
                `players`     SMALLINT UNSIGNED NULL,
                `max_players` SMALLINT UNSIGNED NULL,
                `map`         VARCHAR(64) NULL,
                PRIMARY KEY (`id`),
                KEY `idx_server_time` (`server_id`, `sampled_at`),
                KEY `idx_time` (`sampled_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
        Database::createTable(
            'CREATE TABLE IF NOT EXISTS `' . self::STATE . '` (
                `server_id`  INT NOT NULL,
                `status`     VARCHAR(8) NOT NULL DEFAULT \'up\',
                `fail_count` INT NOT NULL DEFAULT 0,
                `changed_at` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`server_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    }

    public static function enabled(): bool
    {
        return Settings::bool('monitor_enabled', true);
    }

    /**
     * Sample every server when the configured interval has elapsed.
     * @return int number of servers sampled
     */
    public static function sample(bool $force = false, ?int $now = null): int
    {
        if (!self::enabled() || !Database::tableExists(self::SAMPLES)) {
            return 0;
        }
        $now ??= time();
        $interval = max(1, (int) Settings::get('monitor_interval', '2')) * 60;
        $last = (int) Settings::get('monitor_last_sample', '0');
        if (!$force && $last > 0 && $now - $last < $interval - 5) {
            return 0;
        }
        Settings::set('monitor_last_sample', (string) $now);

        $count = 0;
        foreach (ServerModel::options() as $server) {
            [$host, $port] = A2S::endpoint((string) $server['address']);
            $info = $host !== '' ? A2S::info($host, $port, 1.5) : ['online' => false];
            $online = !empty($info['online']);
            Database::run(
                'INSERT INTO `' . self::SAMPLES . '` (server_id, sampled_at, online, players, max_players, map) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    (int) $server['id'], $now, $online ? 1 : 0,
                    $online ? (int) ($info['players'] ?? 0) : null,
                    $online ? (int) ($info['max_players'] ?? 0) : null,
                    $online ? mb_substr((string) ($info['map'] ?? ''), 0, 64) : null,
                ]
            );
            self::track($server, $online, $now);
            $count++;
        }
        self::purge($now);
        return $count;
    }

    /** Update the up/down state of one server and alert on transitions. */
    private static function track(array $server, bool $online, int $now): void
    {
        $id = (int) $server['id'];
        $state = Database::one('SELECT status, fail_count, changed_at FROM `' . self::STATE . '` WHERE server_id = ?', [$id])
            ?? ['status' => 'up', 'fail_count' => 0, 'changed_at' => $now];
        $status = (string) $state['status'];
        $fails = (int) $state['fail_count'];
        $changed = (int) $state['changed_at'];
        $downAfter = max(1, (int) Settings::get('monitor_down_after', '3'));

        if ($online) {
            if ($status === 'down') {
                self::alert($server, false, $now - $changed);
                $changed = $now;
            }
            $status = 'up';
            $fails = 0;
        } else {
            $fails++;
            if ($status !== 'down' && $fails >= $downAfter) {
                $status = 'down';
                $changed = $now;
                self::alert($server, true, 0);
            }
        }
        Database::run(
            'INSERT INTO `' . self::STATE . '` (server_id, status, fail_count, changed_at) VALUES (?, ?, ?, ?)'
            . Database::onDuplicateUpdate(['status', 'fail_count', 'changed_at']),
            [$id, $status, $fails, $changed]
        );
    }

    private static function alert(array $server, bool $down, int $downFor): void
    {
        $name = (string) ($server['hostname'] ?: $server['address']);
        Logger::log($down ? 'monitor.down' : 'monitor.up', (string) $server['id'], $name);
        $webhook = trim((string) Settings::get('monitor_webhook', ''));
        if ($webhook === '' && Discord::enabled()) {
            $webhook = (string) Settings::get('discord_webhook', '');
        }
        if ($webhook === '') {
            return;
        }
        $fields = ['Server' => $name, 'Address' => (string) $server['address']];
        if (!$down) {
            $fields['Downtime'] = self::duration($downFor);
        }
        try {
            Discord::sendToWebhook($webhook, $down ? 'Server offline' : 'Server back online', $down ? 'ban' : 'unban', $fields);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] monitor alert failed: ' . $e->getMessage());
        }
    }

    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 3600) {
            return max(1, intdiv($seconds, 60)) . ' min';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
        }
        return intdiv($seconds, 86400) . ' d ' . intdiv($seconds % 86400, 3600) . ' h';
    }

    /** Delete samples older than the retention, in small batches. */
    private static function purge(int $now): void
    {
        $days = max(1, (int) Settings::get('monitor_retention_days', '30'));
        Database::run('DELETE FROM `' . self::SAMPLES . '` WHERE sampled_at < ? LIMIT 5000', [$now - $days * 86400]);
    }

    /**
     * Per-server overview: current state plus uptime and player stats for the
     * last 24 hours and 7 days.
     * @return array<int,array<string,mixed>>
     */
    public static function overview(?int $now = null): array
    {
        $now ??= time();
        $out = [];
        if (!Database::tableExists(self::SAMPLES)) {
            return $out;
        }
        $states = [];
        foreach (Database::all('SELECT server_id, status, changed_at FROM `' . self::STATE . '`') as $row) {
            $states[(int) $row['server_id']] = $row;
        }
        foreach (ServerModel::options() as $server) {
            $id = (int) $server['id'];
            $last = Database::one(
                'SELECT online, players, max_players, map, sampled_at FROM `' . self::SAMPLES . '` WHERE server_id = ? ORDER BY sampled_at DESC LIMIT 1',
                [$id]
            );
            $out[] = [
                'id'         => $id,
                'name'       => (string) ($server['hostname'] ?: $server['address']),
                'address'    => (string) $server['address'],
                'status'     => (string) ($states[$id]['status'] ?? ($last ? ((int) $last['online'] ? 'up' : 'down') : 'unknown')),
                'changed_at' => (int) ($states[$id]['changed_at'] ?? 0),
                'last'       => $last,
                'day'        => self::stats($id, $now - 86400),
                'week'       => self::stats($id, $now - 7 * 86400),
            ];
        }
        return $out;
    }

    /** @return array{samples:int,uptime:?float,avg:?float,peak:?int} */
    public static function stats(int $serverId, int $since): array
    {
        $row = Database::one(
            'SELECT COUNT(*) AS n, SUM(online) AS up, AVG(CASE WHEN online = 1 THEN players END) AS avg_players, MAX(players) AS peak
             FROM `' . self::SAMPLES . '` WHERE server_id = ? AND sampled_at >= ?',
            [$serverId, $since]
        ) ?? [];
        $n = (int) ($row['n'] ?? 0);
        return [
            'samples' => $n,
            'uptime'  => $n > 0 ? round(100 * (int) $row['up'] / $n, 2) : null,
            'avg'     => $row['avg_players'] !== null ? round((float) $row['avg_players'], 1) : null,
            'peak'    => $row['peak'] !== null ? (int) $row['peak'] : null,
        ];
    }

    /**
     * Player history in fixed buckets for a chart.
     * @return array<int,array{t:int,players:?float,online:?float}>
     */
    public static function series(int $serverId, int $since, int $buckets, ?int $now = null): array
    {
        $now ??= time();
        $buckets = max(1, $buckets);
        $size = max(60, intdiv($now - $since, $buckets));
        $rows = Database::all(
            'SELECT FLOOR((sampled_at - ' . (int) $since . ') / ' . (int) $size . ') AS b,
                    AVG(CASE WHEN online = 1 THEN players END) AS players, AVG(online) AS online
             FROM `' . self::SAMPLES . '` WHERE server_id = ? AND sampled_at >= ? GROUP BY b',
            [$serverId, $since]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['b']] = $r;
        }
        $out = [];
        for ($i = 0; $i < $buckets; $i++) {
            $r = $map[$i] ?? null;
            $out[] = [
                't'       => $since + $i * $size,
                'players' => $r && $r['players'] !== null ? round((float) $r['players'], 1) : null,
                'online'  => $r ? round((float) $r['online'], 2) : null,
            ];
        }
        return $out;
    }
}
