<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Settings;

/**
 * K4-System's flat tables, distinct from K4-Zenith's JSON storage.
 *
 * K4-System stores one row per player across three tables (prefix configurable):
 *   - <prefix>k4ranks : steam_id, name, lastseen, `rank` (rank NAME as text), points
 *   - <prefix>k4stats : steam_id, name, lastseen, + flat stat columns
 *                       (kills, deaths, assists, headshots, rounds_ct, rounds_t,
 *                        rounds_overall, game_win, game_lose, mvp, ...)
 *   - <prefix>k4times : steam_id, name, lastseen, `all`, ct, t, spec, dead, alive
 *                       (play time in SECONDS; `all` is the total)
 *
 * Row shapes match ZenithModel. Play time is converted from plugin seconds to
 * panel minutes on reads, and back to seconds on writes.
 */
final class K4SystemModel
{
    private static function prefix(): string
    {
        return (string) Settings::get('k4_prefix', (string) Config::get('k4system.prefix', ''));
    }

    private static function ranksTable(): string
    {
        return self::prefix() . (string) Settings::get('k4_ranks_table', (string) Config::get('k4system.ranks_table', 'k4ranks'));
    }

    private static function statsTable(): string
    {
        return self::prefix() . (string) Settings::get('k4_stats_table', (string) Config::get('k4system.stats_table', 'k4stats'));
    }

    private static function timesTable(): string
    {
        return self::prefix() . (string) Settings::get('k4_times_table', (string) Config::get('k4system.times_table', 'k4times'));
    }

    private static function steamColumn(): string
    {
        return (string) Settings::get('k4_steam', (string) Config::get('k4system.steam_column', 'steam_id'));
    }

    /** Enabled when the selected backend is K4-System and its ranks table exists. */
    public static function enabled(): bool
    {
        return Database::tableExists(self::ranksTable());
    }

    private static function hasStats(): bool
    {
        return Database::tableExists(self::statsTable());
    }

    private static function hasTimes(): bool
    {
        return Database::tableExists(self::timesTable());
    }

    /** Whitelisted sortable aliases -> ORDER BY expression (prevents SQL injection). */
    private const RANK_SORTS = [
        'points' => 'points', 'kills' => 'kills', 'deaths' => 'deaths',
        'assists' => 'assists', 'headshots' => 'headshots',
        'rounds_ct' => 'rounds_ct', 'rounds_t' => 'rounds_t', 'rounds_overall' => 'rounds_overall',
        'games_won' => 'games_won', 'games_lost' => 'games_lost',
    ];

    private static function orderClause(array $whitelist, string $sort, string $dir, string $default): string
    {
        $col = $whitelist[$sort] ?? $whitelist[$default];
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        return $col . ' ' . $dir;
    }

    /** All k4stats numeric columns, used by resetStats to zero everything. */
    private const STAT_COLUMNS = [
        'kills', 'firstblood', 'deaths', 'assists', 'shoots', 'hits_taken', 'hits_given', 'headshots',
        'chest_hits', 'stomach_hits', 'left_arm_hits', 'right_arm_hits', 'left_leg_hits', 'right_leg_hits',
        'neck_hits', 'unused_hits', 'gear_hits', 'special_hits', 'grenades', 'mvp', 'round_win', 'round_lose',
        'game_win', 'game_lose', 'rounds_overall', 'rounds_ct', 'rounds_t', 'bomb_planted', 'bomb_defused',
        'hostage_rescued', 'hostage_killed', 'noscope_kill', 'penetrated_kill', 'thrusmoke_kill', 'flashed_kill',
        'dominated_kill', 'revenge_kill', 'assist_flash',
    ];

                                                                        
    // Ranks leaderboard
                                                                        
    public static function ranksLeaderboard(int $page, int $perPage, string $sort = 'points', string $dir = 'desc'): ?Paginator
    {
        if (!self::enabled()) {
            return null;
        }
        $sc    = self::steamColumn();
        $ranks = self::ranksTable();
        $stats = self::statsTable();
        $order = self::orderClause(self::RANK_SORTS, $sort, $dir, 'points');

        // Stat columns come from k4stats via a LEFT JOIN. When the stats table is
        // absent, the stat aliases are zero so the page still renders ranks.
        if (self::hasStats()) {
            $base = "SELECT r.`{$sc}` AS steam_id, r.`name` AS name,
                            r.`points` AS points, r.`rank` AS rank_name,
                            COALESCE(s.`kills`, 0)          AS kills,
                            COALESCE(s.`deaths`, 0)         AS deaths,
                            COALESCE(s.`assists`, 0)        AS assists,
                            COALESCE(s.`headshots`, 0)      AS headshots,
                            COALESCE(s.`rounds_ct`, 0)      AS rounds_ct,
                            COALESCE(s.`rounds_t`, 0)       AS rounds_t,
                            COALESCE(s.`rounds_overall`, 0) AS rounds_overall,
                            COALESCE(s.`game_win`, 0)       AS games_won,
                            COALESCE(s.`game_lose`, 0)      AS games_lost,
                            r.`lastseen` AS last_online
                     FROM `{$ranks}` r
                     LEFT JOIN `{$stats}` s ON s.`{$sc}` = r.`{$sc}`";
        } else {
            $base = "SELECT r.`{$sc}` AS steam_id, r.`name` AS name,
                            r.`points` AS points, r.`rank` AS rank_name,
                            0 AS kills, 0 AS deaths, 0 AS assists, 0 AS headshots,
                            0 AS rounds_ct, 0 AS rounds_t, 0 AS rounds_overall,
                            0 AS games_won, 0 AS games_lost,
                            r.`lastseen` AS last_online
                     FROM `{$ranks}` r";
        }
        $count = "SELECT COUNT(*) FROM `{$ranks}` r";

        return Paginator::make($base, $count, [], $page, $perPage, $order);
    }

                                                                        
    // Play time leaderboard (k4times.all is SECONDS -> expose minutes)
                                                                        
    public static function playtimeLeaderboard(int $page, int $perPage, string $sort = 'playtime', string $dir = 'desc'): ?Paginator
    {
        if (!self::enabled() || !self::hasTimes()) {
            return null;
        }
        $sc    = self::steamColumn();
        $times = self::timesTable();
        $stats = self::statsTable();
        $sortMap = [
            'playtime' => 'playtime_minutes',
            'ct'       => 'ct_minutes',
            't'        => 't_minutes',
            'spec'     => 'spec_minutes',
            'dead'     => 'dead_minutes',
            'alive'    => 'alive_minutes',
        ];
        $order = self::orderClause($sortMap, $sort, $dir, 'playtime');

        $killsSel  = self::hasStats() ? 'COALESCE(s.`kills`, 0)' : '0';
        $deathsSel = self::hasStats() ? 'COALESCE(s.`deaths`, 0)' : '0';
        $join = self::hasStats() ? "LEFT JOIN `{$stats}` s ON s.`{$sc}` = tm.`{$sc}`" : '';
        $castMin = static fn(string $col): string => Database::castInt("tm.`{$col}` / 60");

        $base = "SELECT tm.`{$sc}` AS steam_id, tm.`name` AS name,
                        {$castMin('all')} AS playtime_minutes,
                        {$castMin('ct')} AS ct_minutes,
                        {$castMin('t')} AS t_minutes,
                        {$castMin('spec')} AS spec_minutes,
                        {$castMin('dead')} AS dead_minutes,
                        {$castMin('alive')} AS alive_minutes,
                        {$killsSel} AS kills,
                        {$deathsSel} AS deaths,
                        tm.`lastseen` AS last_online
                 FROM `{$times}` tm
                 {$join}";
        $count = "SELECT COUNT(*) FROM `{$times}` tm";

        return Paginator::make($base, $count, [], $page, $perPage, $order);
    }

                                                                        
    // Stats leaderboard
                                                                        
    public static function statsLeaderboard(int $page, int $perPage): ?Paginator
    {
        if (!self::enabled() || !self::hasStats()) {
            return null;
        }
        $sc    = self::steamColumn();
        $stats = self::statsTable();

        $base = "SELECT `{$sc}` AS steam_id, `name` AS name,
                        `kills` AS kills, `deaths` AS deaths,
                        `assists` AS assists, `headshots` AS headshots
                 FROM `{$stats}`";
        $count = "SELECT COUNT(*) FROM `{$stats}`";

        return Paginator::make($base, $count, [], $page, $perPage, 'kills DESC');
    }

                                                                        
    // Resets (single player)
                                                                        
    public static function resetRank(string $steamId): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $sc = self::steamColumn();
        Database::run("UPDATE `" . self::ranksTable() . "` SET `points` = 0 WHERE `{$sc}` = ?", [$steamId]);
        return true;
    }

    public static function resetStats(string $steamId): bool
    {
        if (!self::hasStats()) {
            return false;
        }
        $sc = self::steamColumn();
        $sets = implode(', ', array_map(static fn($c) => "`{$c}` = 0", self::STAT_COLUMNS));
        Database::run("UPDATE `" . self::statsTable() . "` SET {$sets} WHERE `{$sc}` = ?", [$steamId]);
        return true;
    }

    public static function resetPlaytime(string $steamId): bool
    {
        if (!self::hasTimes()) {
            return false;
        }
        $sc = self::steamColumn();
        Database::run(
            "UPDATE `" . self::timesTable() . "` SET `all` = 0, `ct` = 0, `t` = 0, `spec` = 0, `dead` = 0, `alive` = 0 WHERE `{$sc}` = ?",
            [$steamId]
        );
        return true;
    }

                                                                        
    // Resets (everyone) — return the number of rows affected
                                                                        
    public static function resetAllRanks(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        return Database::run("DELETE FROM `" . self::ranksTable() . "`")->rowCount();
    }

    public static function resetAllStats(): int
    {
        if (!self::hasStats()) {
            return 0;
        }
        $sets = implode(', ', array_map(static fn($c) => "`{$c}` = 0", self::STAT_COLUMNS));
        return Database::run("UPDATE `" . self::statsTable() . "` SET {$sets}")->rowCount();
    }

    public static function resetAllPlaytime(): int
    {
        if (!self::hasTimes()) {
            return 0;
        }
        return Database::run(
            "UPDATE `" . self::timesTable() . "` SET `all` = 0, `ct` = 0, `t` = 0, `spec` = 0, `dead` = 0, `alive` = 0"
        )->rowCount();
    }

                                                                        
    // Admin edits
                                                                        
    public static function setPoints(string $steamId, int $points): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $sc = self::steamColumn();
        Database::run("UPDATE `" . self::ranksTable() . "` SET `points` = ? WHERE `{$sc}` = ?", [max(0, $points), $steamId]);
        return true;
    }

    /**
     * Set a player's main statistics. Accepts alias keys kills/deaths/assists/headshots
     * (these map 1:1 to k4stats columns of the same name). Only provided keys change.
     * @param array<string,int> $fields
     */
    public static function setStats(string $steamId, array $fields): bool
    {
        if (!self::hasStats()) {
            return false;
        }
        $allowed = ['kills' => 'kills', 'deaths' => 'deaths', 'assists' => 'assists', 'headshots' => 'headshots'];
        $sets = [];
        $params = [];
        foreach ($fields as $alias => $val) {
            if (!isset($allowed[$alias])) {
                continue;
            }
            $sets[] = "`" . $allowed[$alias] . "` = ?";
            $params[] = max(0, (int) $val);
        }
        if (!$sets) {
            return false;
        }
        $sc = self::steamColumn();
        $params[] = $steamId;
        Database::run("UPDATE `" . self::statsTable() . "` SET " . implode(', ', $sets) . " WHERE `{$sc}` = ?", $params);
        return true;
    }

    /** Set a player's total play time. Input is MINUTES; K4-System stores SECONDS. */
    public static function setPlaytime(string $steamId, int $minutes): bool
    {
        if (!self::hasTimes()) {
            return false;
        }
        $sc = self::steamColumn();
        Database::run("UPDATE `" . self::timesTable() . "` SET `all` = ? WHERE `{$sc}` = ?", [max(0, $minutes) * 60, $steamId]);
        return true;
    }

                                                                        
    // Dashboard + contests
                                                                        
    /**
     * Top N players by rank points, with kills/deaths for the dashboard widget.
     * Same row shape as ZenithModel::top().
     * @return array<int, array<string, mixed>>
     */
    public static function top(int $limit = 5, ?string $steamId = null, int $offset = 0): array
    {
        if (!self::enabled()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $sc    = self::steamColumn();
        $ranks = self::ranksTable();
        $stats = self::statsTable();
        $where = $steamId !== null ? " WHERE r.`{$sc}` = ?" : '';
        $parameters = $steamId !== null ? [$steamId] : [];

        if (self::hasStats()) {
            return Database::all(
                "SELECT r.`{$sc}` AS steam_id, r.`name` AS name,
                        r.`points` AS points, r.`rank` AS rank_name,
                        COALESCE(s.`kills`, 0) AS kills, COALESCE(s.`deaths`, 0) AS deaths,
                        r.`lastseen` AS last_online
                 FROM `{$ranks}` r
                 LEFT JOIN `{$stats}` s ON s.`{$sc}` = r.`{$sc}`
                 {$where}
                 ORDER BY r.`points` DESC, r.`{$sc}` ASC
                 LIMIT {$limit} OFFSET {$offset}",
                $parameters
            );
        }
        return Database::all(
            "SELECT `{$sc}` AS steam_id, `name` AS name, `points` AS points, `rank` AS rank_name,
                    0 AS kills, 0 AS deaths, `lastseen` AS last_online
             FROM `{$ranks}`" . ($steamId !== null ? " WHERE `{$sc}` = ?" : '') . " ORDER BY `points` DESC, `{$sc}` ASC LIMIT {$limit} OFFSET {$offset}",
            $parameters
        );
    }

    /**
     * Top N players by TOTAL PLAY TIME. Row shape matches ZenithModel::topByPlaytime()
     * (the "points" field holds play-time MINUTES here).
     * @return array<int, array<string, mixed>>
     */
    public static function topByPlaytime(int $limit = 5, string $source = 'all', int $offset = 0): array
    {
        if (!self::enabled() || !self::hasTimes()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $sc    = self::steamColumn();
        $times = self::timesTable();
        // k4times stores seconds; 'all' is the total, 'ct_t' sums ct + t.
        $secs = $source === 'ct_t' ? '(`ct` + `t`)' : '`all`';
        $castSecs = Database::castInt("{$secs} / 60");

        return Database::all(
            "SELECT `{$sc}` AS steam_id, `name` AS name,
                    {$castSecs} AS points, '' AS rank_name,
                    0 AS kills, 0 AS deaths, `lastseen` AS last_online
             FROM `{$times}`
             ORDER BY points DESC, `{$sc}` ASC
             LIMIT {$limit} OFFSET {$offset}"
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function topByMetric(string $metric, int $limit = 5, string $playtimeSource = 'all', int $offset = 0): array
    {
        return $metric === 'playtime' ? self::topByPlaytime($limit, $playtimeSource, $offset) : self::top($limit, null, $offset);
    }

    /**
     * Most-recently-active players by lastseen (newest first).
     * Row shape mirrors top(). @return array<int, array<string, mixed>>
     */
    public static function recentActive(int $limit = 5): array
    {
        if (!self::enabled()) {
            return [];
        }
        $limit = max(1, min(25, $limit));
        $sc    = self::steamColumn();
        $ranks = self::ranksTable();
        $stats = self::statsTable();
        $killsSel  = self::hasStats() ? 'COALESCE(s.`kills`, 0)' : '0';
        $deathsSel = self::hasStats() ? 'COALESCE(s.`deaths`, 0)' : '0';
        $join = self::hasStats() ? "LEFT JOIN `{$stats}` s ON s.`{$sc}` = r.`{$sc}`" : '';

        return Database::all(
            "SELECT r.`{$sc}` AS steam_id, r.`name` AS name,
                    r.`points` AS points, r.`rank` AS rank_name,
                    {$killsSel} AS kills, {$deathsSel} AS deaths,
                    r.`lastseen` AS last_online
             FROM `{$ranks}` r
             {$join}
             WHERE r.`lastseen` IS NOT NULL
             ORDER BY r.`lastseen` DESC
             LIMIT {$limit}"
        );
    }

    /**
     * SteamID64s whose lastseen is at/after $sinceTs. @return string[]
     */
    public static function activeSince(int $sinceTs): array
    {
        if (!self::enabled()) {
            return [];
        }
        $sc = self::steamColumn();
        $ranks = self::ranksTable();
        try {
            $rows = Database::all(
                "SELECT `{$sc}` AS steam_id
                 FROM `{$ranks}`
                 WHERE `lastseen` IS NOT NULL
                   AND CAST(UNIX_TIMESTAMP(`lastseen`) AS SIGNED) >= ?",
                [$sinceTs]
            );
        } catch (\Throwable $e) {
            return [];
        }
        return array_values(array_filter(array_map(static fn($r) => (string) $r['steam_id'], $rows), 'strlen'));
    }

    /**
     * Players active per hour over the last $hours (oldest -> newest).
     * @return array<int, array{label:string, count:int}>
     */
    public static function activityByHour(int $hours = 6): array
    {
        $hours = max(1, min(24, $hours));
        $now = time();
        $hourStart = $now - ($now % 3600);
        $buckets = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $ts = $hourStart - $i * 3600;
            $buckets[] = ['ts' => $ts, 'label' => date('H:00', $ts), 'count' => 0];
        }
        if (!self::enabled()) {
            return array_map(static fn($b) => ['label' => $b['label'], 'count' => $b['count']], $buckets);
        }
        $ranks = self::ranksTable();
        $from  = (int) $buckets[0]['ts'];
        $tsExpr = "CAST(UNIX_TIMESTAMP(`lastseen`) AS SIGNED)";
        try {
            $rows = Database::all(
                "SELECT FLOOR(({$tsExpr} - {$from}) / 3600) AS bucket, COUNT(*) AS c
                 FROM `{$ranks}`
                 WHERE `lastseen` IS NOT NULL AND {$tsExpr} >= {$from}
                 GROUP BY bucket",
                []
            );
            foreach ($rows as $r) {
                $b = (int) $r['bucket'];
                if ($b >= 0 && $b < count($buckets)) {
                    $buckets[$b]['count'] = (int) $r['c'];
                }
            }
        } catch (\Throwable $e) {
            // fall through with empty buckets
        }
        return array_map(static fn($b) => ['label' => $b['label'], 'count' => $b['count']], $buckets);
    }

    /** @return array{total:int,active:int} */
    public static function stats(): array
    {
        if (!self::enabled()) {
            return ['total' => 0, 'active' => 0];
        }
        $t = self::ranksTable();
        $total = (int) Database::scalar("SELECT COUNT(*) FROM `{$t}`");
        return ['total' => $total, 'active' => $total];
    }
}
