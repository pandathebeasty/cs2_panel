<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Settings;

/**
 * K4-Zenith integration (ranks + stats).
 *
 * K4-Zenith does NOT use flat "zenith_ranks"/"zenith_stats" tables. Instead it
 * stores everything as JSON columns inside "zenith_player_storage":
 *   - `K4-Zenith-Ranks.storage`  -> { "Points": <long>, "Rank": "<name>" }
 *   - `K4-Zenith-Stats.storage`  -> { "Kills": .., "Deaths": .., "Assists": .., "Headshots": .., ... }
 *   - `K4-Zenith-TimeStats.storage`
 * plus a plain `name` and `last_online` column, and per-weapon/per-map tables.
 *
 * The panel reads those JSON keys with JSON_EXTRACT (same approach as css-bans).
 * As a fallback it also supports the older K4-System flat tables (k4ranks / k4stats),
 * auto-detected: if the flat tables exist and the Zenith storage table does not.
 *
 * All queries are defensive: if the table/column does not exist, they return empty.
 */
final class ZenithModel
{
    /** Connection key: K4-Zenith's own database when configured, else the panel one. */
    private const DB = 'zenith';

    // JSON columns inside zenith_player_storage.
    private const RANKS_COL = 'K4-Zenith-Ranks.storage';
    private const STATS_COL = 'K4-Zenith-Stats.storage';
    private const TIME_COL  = 'K4-Zenith-TimeStats.storage';

    /** The single storage table Zenith writes to (overridable via settings/config). */
    private static function storageTable(): string
    {
        return Settings::get('zenith_storage', Config::get('zenith.storage_table', 'zenith_player_storage'));
    }

    /** Legacy K4-System flat tables (only used as a fallback). */
    private static function legacyRanksTable(): string
    {
        return Settings::get('zenith_ranks', Config::get('zenith.ranks_table', 'k4ranks'));
    }
    private static function legacyStatsTable(): string
    {
        return Settings::get('zenith_stats', Config::get('zenith.stats_table', 'k4stats'));
    }
    private static function steamColumn(): string
    {
        return Settings::get('zenith_steam', Config::get('zenith.steam_column', 'steam_id'));
    }

    /**
     * Extra Zenith stats tables that hold per-map and per-weapon breakdowns
     * (zenith_map_stats / zenith_weapon_stats). Derived from the storage
     * table's prefix so they follow the same table naming, and overridable via
     * settings. Only the ones that actually exist are returned.
     * @return string[]
     */
    private static function extraStatsTables(): array
    {
        // Default names are based on the storage table's prefix. If the storage
        // table is "zenith_player_storage", the prefix is "zenith_".
        $storage = self::storageTable();
        $prefix = 'zenith_';
        if (($pos = strpos($storage, 'player_storage')) !== false) {
            $prefix = substr($storage, 0, $pos);
        }
        $map    = (string) Settings::get('zenith_map_stats', $prefix . 'map_stats');
        $weapon = (string) Settings::get('zenith_weapon_stats', $prefix . 'weapon_stats');

        $out = [];
        foreach ([$map, $weapon] as $t) {
            if ($t !== '' && Database::tableExists($t, self::DB)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** True when the modern Zenith storage table is present. */
    private static function isZenith(): bool
    {
        return Database::tableExists(self::storageTable(), self::DB);
    }

    /** True when the legacy flat K4-System tables are present. */
    private static function isLegacy(): bool
    {
        return Database::tableExists(self::legacyRanksTable(), self::DB);
    }

    public static function enabled(): bool
    {
        if (!Settings::bool('stats_enabled', (bool) Config::get('stats.enabled', true))
            || StatsBackend::which() !== 'zenith'
            || !Settings::bool('zenith_enabled', (bool) Config::get('zenith.enabled', false))) {
            return false;
        }
        return self::isZenith() || self::isLegacy();
    }

    /** Whitelisted sortable aliases -> ORDER BY expression (avoids SQL injection). */
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

                                                                        
    // Ranks leaderboard
                                                                        
    public static function ranksLeaderboard(int $page, int $perPage, string $sort = 'points', string $dir = 'desc', string $search = ''): ?Paginator
    {
        $steamCol = self::steamColumn();
        $order = self::orderClause(self::RANK_SORTS, $sort, $dir, 'points');

        if (self::isZenith()) {
            $t = self::storageTable();
            $ranks = self::RANKS_COL;
            $stats = self::STATS_COL;
            $points = "CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$ranks}`, '$.Points')) AS UNSIGNED)";
            $rank   = "JSON_UNQUOTE(JSON_EXTRACT(`{$ranks}`, '$.Rank'))";
            $num = static fn(string $key): string =>
                "CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$stats}`, '$.{$key}')) AS UNSIGNED)";

            $hasLastOnline = self::firstExistingColumn($t, ['last_online']) !== null;
            $lastSelect = $hasLastOnline ? '`last_online` AS last_online' : 'NULL AS last_online';

            // One row per player: rank points + rank name + the full stat set,
            // all read from the two JSON columns in the same storage row.
            $base = "SELECT `{$steamCol}` AS steam_id,
                            `name` AS name,
                            {$points} AS points,
                            {$rank} AS rank_name,
                            {$num('Kills')}     AS kills,
                            {$num('Deaths')}    AS deaths,
                            {$num('Assists')}   AS assists,
                            {$num('Headshots')} AS headshots,
                            {$num('RoundsCT')}  AS rounds_ct,
                            {$num('RoundsT')}   AS rounds_t,
                            ({$num('RoundsCT')} + {$num('RoundsT')}) AS rounds_overall,
                            {$num('GameWin')}   AS games_won,
                            {$num('GameLose')}  AS games_lost,
                            {$lastSelect}
                     FROM `{$t}`
                     WHERE `{$ranks}` IS NOT NULL";
            $count = "SELECT COUNT(*) FROM `{$t}` WHERE `{$ranks}` IS NOT NULL";

            return Paginator::filtered($base, $count, [], $page, $perPage, $order, self::DB, $search);
        }

        if (self::isLegacy()) {
            $t = self::legacyRanksTable();
            $pointsCol = self::firstExistingColumn($t, ['points', 'value', 'rank_points', 'experience']);
            $nameCol   = self::firstExistingColumn($t, ['name', 'player_name', 'last_name']);
            if ($pointsCol === null) {
                return null;
            }
            $nameSelect = $nameCol ? "`{$nameCol}` AS name" : "'' AS name";
            $base  = "SELECT `{$steamCol}` AS steam_id, {$nameSelect}, `{$pointsCol}` AS points, '' AS rank_name,
                             0 AS kills, 0 AS deaths, 0 AS assists, 0 AS headshots,
                             0 AS rounds_ct, 0 AS rounds_t, 0 AS rounds_overall, 0 AS games_won, 0 AS games_lost,
                             NULL AS last_online
                      FROM `{$t}`";
            $count = "SELECT COUNT(*) FROM `{$t}`";
            // Legacy only has points; other sorts fall back to points.
            $legacyDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
            return Paginator::filtered($base, $count, [], $page, $perPage, "points {$legacyDir}", self::DB, $search);
        }

        return null;
    }

                                                                        
    // Play time leaderboard (K4-Zenith-TimeStats.storage -> TotalPlaytime, in minutes)
                                                                        
    public static function playtimeLeaderboard(int $page, int $perPage, string $sort = 'playtime', string $dir = 'desc', string $search = ''): ?Paginator
    {
        $steamCol = self::steamColumn();
        $sortMap = [
            'playtime' => 'playtime_minutes',
            'ct'       => 'ct_minutes',
            't'        => 't_minutes',
            'spec'     => 'spec_minutes',
            'dead'     => 'dead_minutes',
            'alive'    => 'alive_minutes',
        ];
        $order = self::orderClause($sortMap, $sort, $dir, 'playtime');

        if (!self::isZenith()) {
            return null;
        }
        $t = self::storageTable();
        $time  = 'K4-Zenith-TimeStats.storage';
        $stats = self::STATS_COL;
        $tmKey = static fn(string $key): string =>
            "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$time}`, '$.{$key}')) AS DECIMAL(12,2)), 0)";
        $num = static fn(string $key): string =>
            "CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$stats}`, '$.{$key}')) AS UNSIGNED)";

        $hasLastOnline = self::firstExistingColumn($t, ['last_online']) !== null;
        $lastSelect = $hasLastOnline ? '`last_online` AS last_online' : 'NULL AS last_online';

        $base = "SELECT `{$steamCol}` AS steam_id,
                        `name` AS name,
                        {$tmKey('TotalPlaytime')} AS playtime_minutes,
                        {$tmKey('CounterTerroristPlaytime')} AS ct_minutes,
                        {$tmKey('TerroristPlaytime')} AS t_minutes,
                        {$tmKey('SpectatorPlaytime')} AS spec_minutes,
                        {$tmKey('DeadPlaytime')} AS dead_minutes,
                        {$tmKey('AlivePlaytime')} AS alive_minutes,
                        {$num('Kills')}  AS kills,
                        {$num('Deaths')} AS deaths,
                        {$lastSelect}
                 FROM `{$t}`
                 WHERE `{$time}` IS NOT NULL";
        $count = "SELECT COUNT(*) FROM `{$t}` WHERE `{$time}` IS NOT NULL";

        return Paginator::filtered($base, $count, [], $page, $perPage, $order, self::DB, $search);
    }

                                                                        
    // Stats leaderboard
                                                                        
    public static function statsLeaderboard(int $page, int $perPage, string $search = ''): ?Paginator
    {
        $steamCol = self::steamColumn();

        if (self::isZenith()) {
            $t = self::storageTable();
            $stats = self::STATS_COL;
            $num = static fn(string $key): string =>
                "CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$stats}`, '$.{$key}')) AS UNSIGNED)";

            $base = "SELECT `{$steamCol}` AS steam_id,
                            `name` AS name,
                            {$num('Kills')}    AS kills,
                            {$num('Deaths')}   AS deaths,
                            {$num('Assists')}  AS assists,
                            {$num('Headshots')} AS headshots
                     FROM `{$t}`
                     WHERE `{$stats}` IS NOT NULL";
            $count = "SELECT COUNT(*) FROM `{$t}` WHERE `{$stats}` IS NOT NULL";

            return Paginator::filtered($base, $count, [], $page, $perPage, 'kills DESC', self::DB, $search);
        }

        if (self::isLegacy()) {
            $t = self::legacyStatsTable();
            if (!Database::tableExists($t, self::DB)) {
                return null;
            }
            $killsCol  = self::firstExistingColumn($t, ['kills', 'Kills']);
            $deathsCol = self::firstExistingColumn($t, ['deaths', 'Deaths']);
            $assistsCol = self::firstExistingColumn($t, ['assists', 'Assists']);
            $hsCol     = self::firstExistingColumn($t, ['headshots', 'Headshots']);
            $nameCol   = self::firstExistingColumn($t, ['name', 'player_name', 'last_name']);
            if ($killsCol === null) {
                return null;
            }
            $nameSelect    = $nameCol ? "`{$nameCol}` AS name" : "'' AS name";
            $deathsSelect  = $deathsCol ? "`{$deathsCol}` AS deaths" : '0 AS deaths';
            $assistsSelect = $assistsCol ? "`{$assistsCol}` AS assists" : '0 AS assists';
            $hsSelect      = $hsCol ? "`{$hsCol}` AS headshots" : '0 AS headshots';

            $base = "SELECT `{$steamCol}` AS steam_id, {$nameSelect}, `{$killsCol}` AS kills,
                            {$deathsSelect}, {$assistsSelect}, {$hsSelect}
                     FROM `{$t}`";
            $count = "SELECT COUNT(*) FROM `{$t}`";
            return Paginator::filtered($base, $count, [], $page, $perPage, 'kills DESC', self::DB, $search);
        }

        return null;
    }

                                                                        
                    
                                                                        
    /**
     * Reset a player's rank points to 0.
     * Zenith: JSON_SET the Points key inside the storage column.
     * Legacy: UPDATE the flat points column.
     */
    public static function resetRank(string $steamId): bool
    {
        $steamCol = self::steamColumn();

        if (self::isZenith()) {
            $t = self::storageTable();
            $ranks = self::RANKS_COL;
            Database::run(
                "UPDATE `{$t}`
                 SET `{$ranks}` = JSON_SET(COALESCE(`{$ranks}`, '{}'), '$.Points', 0)
                 WHERE `{$steamCol}` = ?",
                [$steamId], self::DB
            );
            return true;
        }

        if (self::isLegacy()) {
            $t = self::legacyRanksTable();
            $pointsCol = self::firstExistingColumn($t, ['points', 'value', 'rank_points', 'experience']);
            if ($pointsCol === null) {
                return false;
            }
            Database::run("UPDATE `{$t}` SET `{$pointsCol}` = 0 WHERE `{$steamCol}` = ?", [$steamId], self::DB);
            return true;
        }

        return false;
    }

    /**
     * Reset a player's statistics.
     * Zenith: clear the stats storage column back to an empty object.
     * Legacy: delete the row from the flat stats table.
     */
    public static function resetStats(string $steamId): bool
    {
        $steamCol = self::steamColumn();

        if (self::isZenith()) {
            $t = self::storageTable();
            $stats = self::STATS_COL;
            Database::run(
                "UPDATE `{$t}` SET `{$stats}` = NULL WHERE `{$steamCol}` = ?",
                [$steamId], self::DB
            );
            return true;
        }

        if (self::isLegacy()) {
            $t = self::legacyStatsTable();
            if (!Database::tableExists($t, self::DB)) {
                return false;
            }
            Database::run("DELETE FROM `{$t}` WHERE `{$steamCol}` = ?", [$steamId], self::DB);
            return true;
        }

        return false;
    }

    /**
     * Reset EVERYONE's rank points to 0 (destructive, root-only in the controller).
     * Zenith: JSON_SET Points=0 on every row that has the ranks storage.
     * Legacy: UPDATE all points to 0.
     * Returns the number of rows affected.
     */
    public static function resetAllRanks(): int
    {
        if (self::isZenith()) {
            $t = self::storageTable();
            $ranks = self::RANKS_COL;
            // Ranks, stats and play time share this row, so only the rank data
            // is wiped (same as resetAllStats does for the stats column).
            $stmt = Database::run("UPDATE `{$t}` SET `{$ranks}` = NULL", [], self::DB);
            return $stmt->rowCount();
        }

        if (self::isLegacy()) {
            $t = self::legacyRanksTable();
            return Database::run("DELETE FROM `{$t}`", [], self::DB)->rowCount();
        }

        return 0;
    }

    /**
     * Reset EVERYONE's statistics (destructive, root-only in the controller).
     * Zenith: zero the stats column on every row — ranks and play time live in
     * their own columns of the SAME storage table and must not be touched.
     * Legacy: truncate/empty the flat stats table.
     * Returns the number of rows affected.
     */
    public static function resetAllStats(): int
    {
        if (self::isZenith()) {
            // The per-map and per-weapon tables are stats-only, safe to empty.
            foreach (self::extraStatsTables() as $extra) {
                Database::run("DELETE FROM `{$extra}`", [], self::DB);
            }
            $t = self::storageTable();
            $stats = self::STATS_COL;
            $stmt = Database::run("UPDATE `{$t}` SET `{$stats}` = NULL", [], self::DB);
            return $stmt->rowCount();
        }

        if (self::isLegacy()) {
            $t = self::legacyStatsTable();
            if (!Database::tableExists($t, self::DB)) {
                return 0;
            }
            $count = (int) Database::scalar("SELECT COUNT(*) FROM `{$t}`", [], self::DB);
            Database::run("DELETE FROM `{$t}`", [], self::DB);
            return $count;
        }

        return 0;
    }

                                                                        
    // Editing (admin-set values)
                                                                        

    /** Set a player's rank points (Zenith only). */
    public static function setPoints(string $steamId, int $points): bool
    {
        if (!self::isZenith()) {
            // Legacy: update the points column if present.
            if (self::isLegacy()) {
                $t = self::legacyRanksTable();
                $pointsCol = self::firstExistingColumn($t, ['points', 'value', 'rank_points', 'experience']);
                if ($pointsCol === null) {
                    return false;
                }
                Database::run("UPDATE `{$t}` SET `{$pointsCol}` = ? WHERE `" . self::steamColumn() . "` = ?", [max(0, $points), $steamId], self::DB);
                return true;
            }
            return false;
        }
        $t = self::storageTable();
        $ranks = self::RANKS_COL;
        Database::run(
            "UPDATE `{$t}` SET `{$ranks}` = JSON_SET(COALESCE(`{$ranks}`, '{}'), '$.Points', ?) WHERE `" . self::steamColumn() . "` = ?",
            [max(0, $points), $steamId], self::DB
        );
        return true;
    }

    /**
     * Set a player's main statistics (Zenith only). Only the provided keys are
     * updated. Accepts alias keys: kills, deaths, assists, headshots.
     * @param array<string,int> $fields
     */
    public static function setStats(string $steamId, array $fields): bool
    {
        if (!self::isZenith()) {
            return false;
        }
        $map = ['kills' => 'Kills', 'deaths' => 'Deaths', 'assists' => 'Assists', 'headshots' => 'Headshots'];
        $sets = [];
        $params = [];
        foreach ($fields as $alias => $val) {
            if (!isset($map[$alias])) {
                continue;
            }
            $sets[] = "'$." . $map[$alias] . "', ?";
            $params[] = max(0, (int) $val);
        }
        if (!$sets) {
            return false;
        }
        $t = self::storageTable();
        $stats = self::STATS_COL;
        $params[] = $steamId;
        Database::run(
            "UPDATE `{$t}` SET `{$stats}` = JSON_SET(COALESCE(`{$stats}`, '{}'), " . implode(', ', $sets) . ") WHERE `" . self::steamColumn() . "` = ?",
            $params, self::DB
        );
        return true;
    }

    /** Set a player's total play time in minutes (Zenith only). */
    public static function setPlaytime(string $steamId, int $minutes): bool
    {
        if (!self::isZenith()) {
            return false;
        }
        $t = self::storageTable();
        $time = self::TIME_COL;
        Database::run(
            "UPDATE `{$t}` SET `{$time}` = JSON_SET(COALESCE(`{$time}`, '{}'), '$.TotalPlaytime', ?) WHERE `" . self::steamColumn() . "` = ?",
            [max(0, $minutes), $steamId], self::DB
        );
        return true;
    }

    /**
     * Reset a single player's play time (K4-Zenith-TimeStats.storage). Only
     * the TotalPlaytime key is zeroed via JSON_SET — same safe pattern as
     * setPlaytime() — so any other key the plugin keeps in this JSON column
     * is left untouched instead of being wiped by a blanket NULL.
     */
    public static function resetPlaytime(string $steamId): bool
    {
        $steamCol = self::steamColumn();

        if (self::isZenith()) {
            $t = self::storageTable();
            $time = self::TIME_COL;
            Database::run(
                "UPDATE `{$t}` SET `{$time}` = JSON_SET(COALESCE(`{$time}`, '{}'), '$.TotalPlaytime', 0) WHERE `{$steamCol}` = ?",
                [$steamId], self::DB
            );
            return true;
        }

        // No standard legacy play-time table; nothing to reset.
        return false;
    }

    /**
     * Reset EVERYONE's play time (destructive, root-only in the controller).
     * Zenith: zero the TotalPlaytime key on every row via JSON_SET (same safe,
     * key-scoped pattern as the single-player reset), preserving any other
     * data the plugin may keep in this JSON column.
     * Returns the number of rows affected.
     */
    public static function resetAllPlaytime(): int
    {
        if (self::isZenith()) {
            $t = self::storageTable();
            $time = self::TIME_COL;
            // Touch EVERY player (no WHERE filter, which used to skip rows and
            // leave some users with their time intact).
            $stmt = Database::run("UPDATE `{$t}` SET `{$time}` = JSON_SET(COALESCE(`{$time}`, '{}'), '$.TotalPlaytime', 0)", [], self::DB);
            return $stmt->rowCount();
        }

        return 0;
    }

    /**
     * Top N players by rank points, with kills/deaths for the dashboard widget.
     * Returns rows: steam_id, name, points, rank_name, kills, deaths, last_online.
     * @return array<int, array<string, mixed>>
     */
    public static function top(int $limit = 5, ?string $steamId = null, int $offset = 0): array
    {
        if (!self::enabled()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $steamCol = self::steamColumn();
        $playerFilter = $steamId !== null ? " AND `{$steamCol}` = ?" : '';
        $parameters = $steamId !== null ? [$steamId] : [];

        if (self::isZenith()) {
            $t = self::storageTable();
            $ranks = self::RANKS_COL;
            $stats = self::STATS_COL;
            $points = "CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$ranks}`, '$.Points')) AS UNSIGNED)";
            $rank   = "JSON_UNQUOTE(JSON_EXTRACT(`{$ranks}`, '$.Rank'))";
            $kills  = "CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$stats}`, '$.Kills')) AS UNSIGNED)";
            $deaths = "CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$stats}`, '$.Deaths')) AS UNSIGNED)";

            $hasLastOnline = self::firstExistingColumn($t, ['last_online']) !== null;
            $lastSelect = $hasLastOnline ? '`last_online` AS last_online' : "NULL AS last_online";

            return Database::all(
                "SELECT `{$steamCol}` AS steam_id, `name` AS name,
                        {$points} AS points, {$rank} AS rank_name,
                        {$kills} AS kills, {$deaths} AS deaths, {$lastSelect}
                 FROM `{$t}`
                 WHERE `{$ranks}` IS NOT NULL
                 {$playerFilter}
                 ORDER BY points DESC, `{$steamCol}` ASC
                 LIMIT {$limit} OFFSET {$offset}",
                $parameters, self::DB
            );
        }

        // Legacy K4-System flat tables.
        $rt = self::legacyRanksTable();
        $pointsCol = self::firstExistingColumn($rt, ['points', 'value', 'rank_points', 'experience']);
        $nameCol   = self::firstExistingColumn($rt, ['name', 'player_name', 'last_name']);
        if ($pointsCol === null) {
            return [];
        }
        $nameSelect = $nameCol ? "`{$nameCol}` AS name" : "'' AS name";
        return Database::all(
            "SELECT `{$steamCol}` AS steam_id, {$nameSelect}, `{$pointsCol}` AS points,
                    '' AS rank_name, 0 AS kills, 0 AS deaths, NULL AS last_online
             FROM `{$rt}`" . ($steamId !== null ? " WHERE `{$steamCol}` = ?" : '') . " ORDER BY `{$pointsCol}` DESC, `{$steamCol}` ASC LIMIT {$limit} OFFSET {$offset}",
            $parameters, self::DB
        );
    }

    /**
     * Zenith play-time leaders; "points" contains minutes to match top().
     * $source is 'all' (TotalPlaytime) or 'ct_t' (CT + T play time).
     * @return array<int, array<string, mixed>>
     */
    public static function topByPlaytime(int $limit = 5, string $source = 'all', int $offset = 0): array
    {
        if (!self::enabled() || !self::isZenith()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $steamCol = self::steamColumn();
        $t = self::storageTable();
        $time = self::TIME_COL;
        if ($source === 'ct_t') {
            $mins = "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$time}`, '$.CounterTerroristPlaytime')) AS DECIMAL(12,2)), 0)"
                  . " + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$time}`, '$.TerroristPlaytime')) AS DECIMAL(12,2)), 0)";
        } else {
            $mins = "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$time}`, '$.TotalPlaytime')) AS DECIMAL(12,2)), 0)";
        }

        return Database::all(
            "SELECT `{$steamCol}` AS steam_id, `name` AS name,
                    {$mins} AS points, '' AS rank_name,
                    0 AS kills, 0 AS deaths, NULL AS last_online
             FROM `{$t}`
             WHERE `{$time}` IS NOT NULL
             ORDER BY points DESC, `{$steamCol}` ASC
             LIMIT {$limit} OFFSET {$offset}", [], self::DB
        );
    }

    /**
     * Top N by a contest metric: 'playtime' -> topByPlaytime, otherwise rank.
     * @return array<int, array<string, mixed>>
     */
    public static function topByMetric(string $metric, int $limit = 5, string $playtimeSource = 'all', int $offset = 0): array
    {
        return $metric === 'playtime' ? self::topByPlaytime($limit, $playtimeSource, $offset) : self::top($limit, null, $offset);
    }

    /**
     * The most-recently-active players (by last_online), newest first.
     * Row shape mirrors top(): steam_id, name, points, rank_name, kills,
     * deaths, last_online. Returns [] when no usable last_online column exists.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentActive(int $limit = 5): array
    {
        if (!self::enabled() || !self::isZenith()) {
            return [];
        }
        $limit = max(1, min(25, $limit));
        $t = self::storageTable();
        if (self::firstExistingColumn($t, ['last_online']) === null) {
            return [];
        }
        $steamCol = self::steamColumn();
        $ranks = self::RANKS_COL;
        $stats = self::STATS_COL;
        $points = "CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$ranks}`, '$.Points')) AS UNSIGNED)";
        $rank   = "JSON_UNQUOTE(JSON_EXTRACT(`{$ranks}`, '$.Rank'))";
        $kills  = "CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$stats}`, '$.Kills')) AS UNSIGNED)";
        $deaths = "CAST(JSON_UNQUOTE(JSON_EXTRACT(`{$stats}`, '$.Deaths')) AS UNSIGNED)";

        return Database::all(
            "SELECT `{$steamCol}` AS steam_id, `name` AS name,
                    {$points} AS points, {$rank} AS rank_name,
                    {$kills} AS kills, {$deaths} AS deaths,
                    `last_online` AS last_online
             FROM `{$t}`
             WHERE `last_online` IS NOT NULL
             ORDER BY `last_online` DESC
             LIMIT {$limit}", [], self::DB
        );
    }

    /**
     * Count of players whose last activity falls in each of the last 6 hours.
     * Returns 6 buckets oldest -> newest, each: ['label' => 'HH:00', 'count' => n].
     *
     * @return array<int, array{label:string, count:int}>
     */
    public static function activityByHour(int $hours = 6): array
    {
        $buckets = self::emptyHourBuckets($hours);
        if (!self::enabled() || !self::isZenith()) {
            return $buckets;
        }
        $t = self::storageTable();
        if (self::firstExistingColumn($t, ['last_online']) === null) {
            return $buckets;
        }
        return self::fillHourBuckets($buckets, "`last_online`", $t, "`last_online` IS NOT NULL", $hours);
    }

    /**
     * SteamID64s whose last activity is at/after $sinceTs. Used by the activity
     * sampler to detect currently-active players. Returns [] when unavailable.
     *
     * @return string[]
     */
    public static function activeSince(int $sinceTs): array
    {
        if (!self::enabled() || !self::isZenith()) {
            return [];
        }
        $t = self::storageTable();
        if (self::firstExistingColumn($t, ['last_online']) === null) {
            return [];
        }
        $steamCol = self::steamColumn();
        try {
            $rows = Database::all(
                "SELECT `{$steamCol}` AS steam_id
                 FROM `{$t}`
                 WHERE `last_online` IS NOT NULL
                   AND CAST(UNIX_TIMESTAMP(`last_online`) AS SIGNED) >= ?",
                [$sinceTs], self::DB
            );
        } catch (\Throwable $e) {
            return [];
        }
        return array_values(array_filter(array_map(static fn($r) => (string) $r['steam_id'], $rows), 'strlen'));
    }

    /** Build $hours empty buckets, oldest -> newest, keyed by hour start ts. */
    private static function emptyHourBuckets(int $hours): array
    {
        $hours = max(1, min(24, $hours));
        $now = time();
        $hourStart = $now - ($now % 3600); // start of the current hour
        $out = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $ts = $hourStart - $i * 3600;
            $out[] = ['ts' => $ts, 'label' => date('H:00', $ts), 'count' => 0];
        }
        return $out;
    }

    /**
     * Fill hour buckets from a datetime/timestamp column. Handles both a MySQL
     * DATETIME string and a numeric unix column via UNIX_TIMESTAMP coercion.
     *
     * @param array<int,array{ts:int,label:string,count:int}> $buckets
     * @return array<int,array{label:string,count:int}>
     */
    private static function fillHourBuckets(array $buckets, string $col, string $table, string $extraWhere, int $hours): array
    {
        if (!$buckets) {
            return [];
        }
        $from = (int) $buckets[0]['ts'];
        // Coerce the column to a unix timestamp regardless of storage type.
        $tsExpr = "CAST(UNIX_TIMESTAMP({$col}) AS SIGNED)";
        try {
            $rows = Database::all(
                "SELECT FLOOR(({$tsExpr} - {$from}) / 3600) AS bucket, COUNT(*) AS c
                 FROM `{$table}`
                 WHERE {$extraWhere} AND {$tsExpr} >= {$from}
                 GROUP BY bucket",
                [], self::DB
            );
        } catch (\Throwable $e) {
            return $buckets;
        }
        foreach ($rows as $r) {
            $b = (int) $r['bucket'];
            if ($b >= 0 && $b < count($buckets)) {
                $buckets[$b]['count'] = (int) $r['c'];
            }
        }
        // Drop the internal ts key from the result.
        return array_map(static fn($b) => ['label' => $b['label'], 'count' => $b['count']], $buckets);
    }

    public static function stats(): array
    {
        if (!self::enabled()) {
            return ['total' => 0, 'active' => 0];
        }
        $t = self::isZenith() ? self::storageTable() : self::legacyRanksTable();
        return [
            'total'  => (int) Database::scalar("SELECT COUNT(*) FROM `{$t}`", [], self::DB),
            'active' => (int) Database::scalar("SELECT COUNT(*) FROM `{$t}`", [], self::DB),
        ];
    }

    /**
     * Per-request cache of each table's lowercase column list, so repeated
     * firstExistingColumn() calls in one request don't re-query information_schema
     * (which is not free on MySQL). A table's columns never change mid-request.
     * @var array<string, string[]>
     */
    private static array $columnCache = [];

    /** First existing column from a candidate list (or null). Case-insensitive. */
    private static function firstExistingColumn(string $table, array $candidates): ?string
    {
        if (!isset(self::$columnCache[$table])) {
            $cols = Database::all(
                'SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ?',
                [$table], self::DB
            );
            self::$columnCache[$table] = array_map('strtolower', array_column($cols, 'column_name'));
        }
        $available = self::$columnCache[$table];
        foreach ($candidates as $c) {
            if (in_array(strtolower($c), $available, true)) {
                return $c;
            }
        }
        return null;
    }
}
