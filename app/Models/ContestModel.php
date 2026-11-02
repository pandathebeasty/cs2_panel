<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\DevMode;
use App\Core\Lang;
use App\Core\Settings;

/**
 * Rank-based contests.
 *
 * A contest awards VIP to the top-N players (by K4-Zenith rank points) at its
 * end time. Two modes:
 *   - manual: the panel only selects and records the winners (and announces
 *             them on Discord). The admin grants VIP by hand in the game DB.
 *   - auto:   the panel grants VIP automatically to the winners at end time.
 *
 * There is no cron on shared hosting, so time-based processing (drawing winners
 * once a contest's end time passes, plus history retention cleanup) runs lazily
 * from ContestController on each request (see autoProcessDue / purgeOldHistory).
 */
final class ContestModel
{
    public const TABLE   = 'panel_contests';
    public const WINNERS = 'panel_contest_winners';

    /** Guard so we only attempt auto-create once per request. */
    private static bool $ensured = false;

    /**
     * The feature is usable once the tables exist. If they don't, we try to
     * create them on the spot (idempotent CREATE TABLE IF NOT EXISTS), so a
     * fresh upload works without manually running the migration. Falls back to
     * a plain existence check when creation isn't possible (e.g. no DB / dev).
     */
    public static function available(): bool
    {
        if (Database::tableExists(self::TABLE) && Database::tableExists(self::WINNERS)) {
            // Tables exist — but they may predate newer columns (e.g. `prizes`).
            // Make sure those are present so inserts/updates don't 500.
            self::ensureColumns();
            return true;
        }
        self::ensureTables();
        return self::tablesExistFresh();
    }

    /** Live existence check that bypasses the per-request tableExists() cache. */
    private static function tablesExistFresh(): bool
    {
        try {
            $n = (int) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name IN (?, ?)',
                [self::TABLE, self::WINNERS]
            );
            return $n >= 2;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Create the contest tables if they are missing. Best-effort and safe to
     * call repeatedly (CREATE TABLE IF NOT EXISTS). Runs at most once per
     * request. Never throws — a failure just leaves the feature unavailable.
     */
    public static function ensureTables(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        // Skip on the dev in-memory SQLite (different SQL dialect; not needed).
        if (DevMode::enabled() && (string) Config::get('db.name', '') === '') {
            return;
        }

        $contests = 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(128) NOT NULL,
            `vip_group` VARCHAR(64) NOT NULL,
            `vip_days` INT NOT NULL DEFAULT 0,
            `prizes` TEXT NULL,
            `metric` VARCHAR(16) NOT NULL DEFAULT \'rank\',
            `playtime_source` VARCHAR(8) NOT NULL DEFAULT \'all\',
            `max_winners` INT NOT NULL DEFAULT 1,
            `mode` ENUM(\'manual\',\'auto\') NOT NULL DEFAULT \'manual\',
            `reset_stats` TINYINT(1) NOT NULL DEFAULT 0,
            `reset_at_start` TINYINT(1) NOT NULL DEFAULT 0,
            `extend_expiring` TINYINT(1) NOT NULL DEFAULT 0,
            `status` VARCHAR(16) NOT NULL DEFAULT \'active\',
            `start_at` DATETIME NULL,
            `started` TINYINT(1) NOT NULL DEFAULT 0,
            `ends_at` DATETIME NOT NULL,
            `awarded_at` DATETIME NULL,
            `stats_reset_done` TINYINT(1) NOT NULL DEFAULT 0,
            `created_by` BIGINT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`),
            KEY `idx_ends` (`ends_at`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

        $winners = 'CREATE TABLE IF NOT EXISTS `' . self::WINNERS . '` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `contest_id` INT NOT NULL,
            `position` INT NOT NULL DEFAULT 0,
            `steamid` VARCHAR(32) NOT NULL,
            `name` VARCHAR(128) NULL,
            `points` BIGINT NOT NULL DEFAULT 0,
            `vip_group` VARCHAR(64) NULL,
            `vip_days` INT NOT NULL DEFAULT 0,
            `vip_granted` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_contest` (`contest_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

        try {
            Database::run($contests);
            Database::run($winners);
            self::ensureColumns();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] contest ensureTables failed: ' . $e->getMessage());
        }
    }

    /**
     * Add columns introduced after the initial tables (e.g. per-position
     * prizes) to installs that predate them. Runs at most once per request.
     * This is what fixes "Unknown column 'prizes'" on older databases where the
     * tables already exist so ensureTables() would otherwise return early.
     */
    private static bool $columnsEnsured = false;
    public static function ensureColumns(): void
    {
        if (self::$columnsEnsured) {
            return;
        }
        self::$columnsEnsured = true;

        if (DevMode::enabled() && (string) Config::get('db.name', '') === '') {
            return;
        }
        $definitions = [
            self::TABLE => [
                'prizes' => 'TEXT NULL',
                'metric' => "VARCHAR(16) NOT NULL DEFAULT 'rank'",
                'playtime_source' => "VARCHAR(8) NOT NULL DEFAULT 'all'",
                'start_at' => 'DATETIME NULL',
                'started' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'reset_at_start' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'extend_expiring' => 'TINYINT(1) NOT NULL DEFAULT 0',
                // Store credits (cs2-store) default prize amount.
                'credit_amount' => 'INT NOT NULL DEFAULT 0',
            ],
            self::WINNERS => [
                'vip_group' => 'VARCHAR(64) NULL',
                'vip_days' => 'INT NOT NULL DEFAULT 0',
                // Per-winner credits prize + whether applied.
                'credit_amount' => 'INT NOT NULL DEFAULT 0',
                'credits_granted' => 'TINYINT(1) NOT NULL DEFAULT 0',
            ],
        ];
        try {
            $rows = Database::all(
                'SELECT table_name, column_name, column_type FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name IN (?, ?)',
                [self::TABLE, self::WINNERS]
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] contest column inspection failed: ' . $e->getMessage());
            return;
        }
        $existing = [];
        foreach ($rows as $row) {
            $existing[(string) $row['table_name']][(string) $row['column_name']] = strtolower((string) $row['column_type']);
        }
        foreach ($definitions as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (isset($existing[$table][$column])) {
                    continue;
                }
                try {
                    Database::run("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                } catch (\Throwable $e) {
                    error_log('[CS2Panel] contest add column ' . $table . '.' . $column . ': ' . $e->getMessage());
                }
            }
        }

        // Older installs used ENUM; widen it only when it is not already VARCHAR.
        $statusType = $existing[self::TABLE]['status'] ?? '';
        if ($statusType !== '' && !str_starts_with($statusType, 'varchar')) {
        try {
            Database::run("ALTER TABLE `" . self::TABLE . "` MODIFY `status` VARCHAR(16) NOT NULL DEFAULT 'active'");
        } catch (\Throwable $e) {
            error_log('[CS2Panel] contest status-column update failed: ' . $e->getMessage());
        }
        }
    }

    /** Add a column only if it isn't already present (version-portable). */
    private static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        try {
            $exists = (int) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            );
            if ($exists === 0) {
                Database::run("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] addColumnIfMissing ' . $table . '.' . $column . ': ' . $e->getMessage());
        }
    }

                                                                        
    // CRUD
                                                                        

    /**
     * Create a contest.
     * @param array{title:string,vip_group:string,vip_days:int,max_winners:int,mode:string,reset_stats:bool,ends_at:string,prizes?:array} $data
     * @return int new contest id (0 on failure)
     */
    public static function create(array $data, ?string $createdBy = null): int
    {
        if (!self::available()) {
            return 0;
        }
        $mode = in_array($data['mode'] ?? 'manual', ['manual', 'auto'], true) ? $data['mode'] : 'manual';

        // A future start_at makes the contest 'scheduled' (not yet started);
        // no start (or a past one) means it starts immediately as 'active'.
        $startAt = trim((string) ($data['start_at'] ?? ''));
        $startAt = $startAt !== '' ? $startAt : null;
        $startsInFuture = $startAt !== null && strtotime($startAt) > time();
        $status  = $startsInFuture ? 'scheduled' : 'active';
        $started = $startsInFuture ? 0 : 1;

        Database::run(
            'INSERT INTO ' . self::TABLE . '
                (title, vip_group, vip_days, credit_amount, prizes, metric, playtime_source, max_winners, mode, reset_stats, reset_at_start, extend_expiring, status, start_at, started, ends_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                mb_substr(trim((string) $data['title']), 0, 128),
                mb_substr(trim((string) $data['vip_group']), 0, 64),
                max(0, (int) ($data['vip_days'] ?? 0)),
                max(0, (int) ($data['credit_amount'] ?? 0)),
                self::encodePrizes($data['prizes'] ?? []),
                self::normalizeMetric($data['metric'] ?? 'rank'),
                self::normalizePlaytimeSource($data['playtime_source'] ?? 'all'),
                max(1, (int) ($data['max_winners'] ?? 1)),
                $mode,
                !empty($data['reset_stats']) ? 1 : 0,
                !empty($data['reset_at_start']) ? 1 : 0,
                !empty($data['extend_expiring']) ? 1 : 0,
                $status,
                $startAt,
                $started,
                (string) $data['ends_at'],
                $createdBy !== null ? $createdBy : null,
            ]
        );
        return (int) Database::lastInsertId();
    }

    /**
     * Edit an existing contest (only while it's still active/before draw).
     * @param array{title:string,vip_group:string,vip_days:int,max_winners:int,mode:string,reset_stats:bool,ends_at:string,prizes?:array} $data
     */
    public static function update(int $id, array $data): bool
    {
        if (!self::available()) {
            return false;
        }
        $mode = in_array($data['mode'] ?? 'manual', ['manual', 'auto'], true) ? $data['mode'] : 'manual';
        $startAt = trim((string) ($data['start_at'] ?? ''));
        $startAt = $startAt !== '' ? $startAt : null;
        Database::run(
            'UPDATE ' . self::TABLE . '
                SET title = ?, vip_group = ?, vip_days = ?, credit_amount = ?, prizes = ?, metric = ?, playtime_source = ?, max_winners = ?,
                    mode = ?, reset_stats = ?, reset_at_start = ?, extend_expiring = ?, start_at = ?, ends_at = ?
              WHERE id = ?',
            [
                mb_substr(trim((string) $data['title']), 0, 128),
                mb_substr(trim((string) $data['vip_group']), 0, 64),
                max(0, (int) ($data['vip_days'] ?? 0)),
                max(0, (int) ($data['credit_amount'] ?? 0)),
                self::encodePrizes($data['prizes'] ?? []),
                self::normalizeMetric($data['metric'] ?? 'rank'),
                self::normalizePlaytimeSource($data['playtime_source'] ?? 'all'),
                max(1, (int) ($data['max_winners'] ?? 1)),
                $mode,
                !empty($data['reset_stats']) ? 1 : 0,
                !empty($data['reset_at_start']) ? 1 : 0,
                !empty($data['extend_expiring']) ? 1 : 0,
                $startAt,
                (string) $data['ends_at'],
                $id,
            ]
        );
        return true;
    }

    /** Only 'rank' or 'playtime' are valid winner-selection metrics. */
    private static function normalizeMetric(string $metric): string
    {
        return $metric === 'playtime' ? 'playtime' : 'rank';
    }

    /** Only 'all' or 'ct_t' are valid playtime sources for contests. */
    private static function normalizePlaytimeSource(string $source): string
    {
        return $source === 'ct_t' ? 'ct_t' : 'all';
    }

    /**
     * Normalize + JSON-encode a per-position prize map.
     * Input: [position => ['group' => str, 'days' => int], ...]. Positions with
     * an empty group are dropped (they fall back to the contest default).
     */
    private static function encodePrizes(array $prizes): ?string
    {
        $clean = [];
        foreach ($prizes as $pos => $prize) {
            $pos = (int) $pos;
            if ($pos < 1) {
                continue;
            }
            $group   = trim((string) ($prize['group'] ?? ''));
            $credits = max(0, (int) ($prize['credits'] ?? 0));
            // Keep a position if it grants a VIP group OR credits.
            if ($group === '' && $credits <= 0) {
                continue;
            }
            $clean[$pos] = [
                'group'   => mb_substr($group, 0, 64),
                'days'    => max(0, (int) ($prize['days'] ?? 0)),
                'credits' => $credits,
            ];
        }
        return $clean ? json_encode($clean) : null;
    }

    /**
     * @return array<int,array{group:string,days:int,credits:int}>
     */
    public static function decodePrizes(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $pos => $prize) {
            if (!is_array($prize)) {
                continue;
            }
            $out[(int) $pos] = [
                'group'   => (string) ($prize['group'] ?? ''),
                'days'    => (int) ($prize['days'] ?? 0),
                'credits' => (int) ($prize['credits'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Use the contest default only when a position has no configured prize.
     * @param array<string,mixed> $contest
     * @return array{group:string,days:int,credits:int}
     */
    public static function prizeFor(array $contest, int $position): array
    {
        $prizes = self::decodePrizes($contest['prizes'] ?? null);
        if (isset($prizes[$position]) && ($prizes[$position]['group'] !== '' || (int) $prizes[$position]['credits'] > 0)) {
            return $prizes[$position];
        }
        return [
            'group'   => (string) $contest['vip_group'],
            'days'    => (int) $contest['vip_days'],
            'credits' => (int) ($contest['credit_amount'] ?? 0),
        ];
    }

    /** @return array{group:string,days:int,credits:int} */
    public static function winnerPrize(array $contest, array $winner): array
    {
        $fallback = self::prizeFor($contest, (int) ($winner['position'] ?? 1));
        // An empty stored group or zero credits is an explicit prize snapshot.
        return [
            'group' => trim((string) ($winner['vip_group'] ?? $fallback['group'])),
            'days' => max(0, (int) ($winner['vip_days'] ?? $fallback['days'])),
            'credits' => max(0, (int) ($winner['credit_amount'] ?? $fallback['credits'])),
        ];
    }

    public static function prizeLabel(array $prize): string
    {
        $parts = [];
        $group = trim((string) ($prize['group'] ?? ''));
        if ($group !== '') {
            $days = (int) ($prize['days'] ?? 0);
            $parts[] = $group . ' · ' . ($days > 0 ? Lang::t('discord.days_n', [$days]) : Lang::t('common.permanent'));
        }
        $credits = max(0, (int) ($prize['credits'] ?? 0));
        if ($credits > 0) $parts[] = number_format($credits) . ' ' . Lang::t('credits.credits');
        return $parts ? implode(' + ', $parts) : Lang::t('common.none');
    }

    public static function hasPendingPrize(array $contest, array $winner): bool
    {
        $prize = self::winnerPrize($contest, $winner);
        return ($prize['group'] !== '' && empty($winner['vip_granted']))
            || ($prize['credits'] > 0 && empty($winner['credits_granted']));
    }

    /**
     * True when a contest grants ANY VIP (a default VIP group, or a VIP group
     * on at least one per-position prize). A credits-only contest returns
     * false, so VIP holders are not skipped during selection.
     * @param array<string,mixed> $contest
     */
    public static function grantsVip(array $contest): bool
    {
        if (trim((string) ($contest['vip_group'] ?? '')) !== '') {
            return true;
        }
        foreach (self::decodePrizes($contest['prizes'] ?? null) as $prize) {
            if (trim((string) ($prize['group'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    public static function find(int $id): ?array
    {
        if (!self::available()) {
            return null;
        }
        return Database::one('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1', [$id]);
    }

    /** Winners of a contest, ordered by position. */
    public static function winners(int $contestId): array
    {
        if (!self::available()) {
            return [];
        }
        return Database::all(
            'SELECT * FROM ' . self::WINNERS . ' WHERE contest_id = ? ORDER BY position ASC, id ASC',
            [$contestId]
        );
    }

    /**
     * All contests (active first, then finished), newest first, each with its
     * winner count. Used for the index/history listing.
     */
    public static function all(): array
    {
        if (!self::available()) {
            return [];
        }
        $rows = Database::all(
            'SELECT c.*, (SELECT COUNT(*) FROM ' . self::WINNERS . ' w WHERE w.contest_id = c.id) AS winner_count
             FROM ' . self::TABLE . " c
             ORDER BY (c.status = 'finished') ASC, c.created_at DESC"
        );
        return $rows;
    }

                                            
    public static function delete(int $id): bool
    {
        if (!self::available()) {
            return false;
        }
        Database::run('DELETE FROM ' . self::WINNERS . ' WHERE contest_id = ?', [$id]);
        Database::run('DELETE FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
        return true;
    }

                                                                        
    // Winner selection + awarding
                                                                        

    /**
     * Pick the top-N players by rank points as the winners of a contest and
     * store them. Existing winner rows for the contest are cleared first so
     * this is idempotent. Returns the stored winner rows.
     * @return array<int,array<string,mixed>>
     */
    public static function drawWinners(int $contestId): array
    {
        $contest = self::find($contestId);
        if ($contest === null) {
            return [];
        }
        
        Database::run('DELETE FROM ' . self::WINNERS . ' WHERE contest_id = ?', [$contestId]);

        foreach (self::selectLeaders($contest) as $row) {
            Database::run(
                'INSERT INTO ' . self::WINNERS . '
                    (contest_id, position, steamid, name, points, vip_group, vip_days, credit_amount, vip_granted, credits_granted)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)',
                [
                    $contestId,
                    $row['position'],
                    $row['steamid'],
                    $row['name'],
                    $row['points'],
                    $row['vip_group'],
                    $row['vip_days'],
                    (int) ($row['credit_amount'] ?? 0),
                ]
            );
        }
        return self::winners($contestId);
    }

    /**
     * Who would win if the contest ended right now — READ-ONLY, nothing is
     * stored. Used by the "re-send announcement" action so re-posting an
     * ongoing contest never rewrites its winner rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function previewLeaders(int $contestId): array
    {
        $contest = self::find($contestId);
        return $contest === null ? [] : self::selectLeaders($contest);
    }

    /**
     * Top-N eligible players for a contest, by its configured metric. Players
     * who already own an active VIP are skipped and the next one moves up.
     *
     * @param array<string,mixed> $contest
     * @return array<int,array<string,mixed>>
     */
    private static function selectLeaders(array $contest): array
    {
        $limit  = max(1, (int) $contest['max_winners']);
        $metric = self::normalizeMetric((string) ($contest['metric'] ?? 'rank'));
        $ptSource = self::normalizePlaytimeSource((string) ($contest['playtime_source'] ?? 'all'));

        // Walk the leaderboard page by page (backend pages are max 50 rows):
        // skipped players are replaced by the next ranked one until every
        // place is filled or the leaderboard runs out.
        $pageSize = 50;
        $offset = 0;
        $seen = [];
        $candidates = (static function () use ($metric, $pageSize, $ptSource, &$offset): \Generator {
            while (true) {
                $page = StatsBackend::topByMetric($metric, $pageSize, $ptSource, $offset); // steam_id, name, points, ...
                foreach ($page as $row) {
                    yield $row;
                }
                if (count($page) < $pageSize) {
                    return;
                }
                $offset += $pageSize;
            }
        })();

        $out = [];
        $pos = 0;
        foreach ($candidates as $p) {
            if ($pos >= $limit) {
                break;
            }
            $steamId = (string) ($p['steam_id'] ?? '');
            if ($steamId === '' || isset($seen[$steamId])) {
                continue;
            }
            $seen[$steamId] = true;
            // Skip players who already have an active VIP — UNLESS the contest
            // has "extend expiring VIP" enabled. With it on, a player who
            // already owns VIP still wins: the prize extends their VIP (same
            // group) or is queued to start after it expires (different group).
            // A CREDITS-ONLY contest (grants no VIP at all) never skips: credits
            // can go to anyone regardless of their VIP status.
            // A permanent VIP can never be extended or queued, so such players
            // are always skipped and the next one in the leaderboard moves up.
            $extendOn = !empty($contest['extend_expiring']);
            if (self::grantsVip($contest)) {
                if ($extendOn ? VipModel::hasPermanentVip($steamId) : VipModel::hasActiveVip($steamId)) {
                    continue;
                }
            }
            $pos++;
            $prize = self::prizeFor($contest, $pos);
            $out[] = [
                'position'  => $pos,
                'steamid'   => $steamId,
                'name'      => isset($p['name']) ? mb_substr((string) $p['name'], 0, 128) : null,
                'points'    => (int) ($p['points'] ?? 0),
                'metric'    => $metric,
                'vip_group' => $prize['group'],
                'vip_days'  => $prize['days'],
                'credit_amount' => (int) ($prize['credits'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Grant VIP to every winner of a contest (auto mode). VIP is per-server, so
     * we grant on each configured VIP server, skipping any (player, server)
     * that already has VIP. Marks winners as vip_granted. Best-effort: a single
     * failing grant does not abort the rest.
     * @return int number of winners for which at least one grant was made
     */
    public static function grantVipToWinners(int $contestId): int
    {
        $contest = self::find($contestId);
        if ($contest === null) {
            return 0;
        }
        $granted = 0;
        foreach (self::winners($contestId) as $w) {
            if (self::grantVipToOneWinner($contest, $w)) {
                $granted++;
            }
        }
        if ($granted > 0) {
            \App\Core\ServerSync::refreshVip();
        }
        return $granted;
    }

    /**
     * Manual "Grant" retry for exactly one already-drawn winner row (e.g. after
     * a backup/restore, or when the automatic grant silently skipped them).
     * Uses the exact same per-server logic as grantVipToWinners().
     */
    public static function grantVipToWinner(int $winnerId): bool
    {
        $winner = Database::one('SELECT * FROM ' . self::WINNERS . ' WHERE id = ? LIMIT 1', [$winnerId]);
        if ($winner === null) {
            return false;
        }
        $contest = self::find((int) $winner['contest_id']);
        if ($contest === null) {
            return false;
        }
        $did = self::grantVipToOneWinner($contest, $winner);
        if ($did) {
            \App\Core\ServerSync::refreshVip();
        }
        return $did;
    }

    /**
     * Shared per-winner VIP grant across every configured VIP server. Skips a
     * server where the player already has a real (non-ignored-group) VIP.
     * Best-effort: a single failing grant does not abort the others.
     */
    private static function grantVipToOneWinner(array $contest, array $w): bool
    {
        $steamId = (string) ($w['steamid'] ?? '');
        if ($steamId === '') {
            return false;
        }
        $prize = self::winnerPrize($contest, $w);
        $group = $prize['group'];
        $days = $prize['days'];
        $credits = $prize['credits'];
        $extendOn = !empty($contest['extend_expiring']);
        $name = (string) ($w['name'] ?? 'VIP');
        $did = false;

        // ---- Credits prize (schwarper/cs2-store) ----
        // Granted once per winner (credits_granted guard), independent of VIP.
        if ($credits > 0 && empty($w['credits_granted']) && \App\Models\StoreCreditsModel::tableReady()) {
            try {
                if (\App\Models\StoreCreditsModel::add($steamId, $credits, $name) !== null) {
                    Database::run('UPDATE ' . self::WINNERS . ' SET credits_granted = 1 WHERE id = ?', [(int) $w['id']]);
                    $did = true;
                    try {
                        \App\Core\Discord::notify(
                            'discord.contest_granted_title',
                            'discord.act_contest_granted',
                            'vip',
                            [
                                'contests.prize'  => $credits . ' ' . \App\Core\Lang::t('credits.credits'),
                                'discord.contest' => (string) ($contest['title'] ?? ''),
                            ],
                            ['name' => $name, 'steamid' => $steamId]
                        );
                    } catch (\Throwable $e) {
                        error_log('[CS2Panel] contest credits Discord log failed: ' . $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                error_log('[CS2Panel] contest credits grant failed for ' . $steamId . ': ' . $e->getMessage());
            }
        }

        // ---- VIP prize ---- (skip entirely for a credits-only winner)
        if ($group === '' || !empty($w['vip_granted'])) {
            return $did;
        }

        $servers = VipModel::enabled() ? VipModel::servers() : [];
        $vipDid = false;
        foreach ($servers as $srv) {
            $sid = (int) $srv['serverId'];
            try {
                $hasVip = VipModel::hasRealVipOnServer($steamId, $sid);

                if (!$hasVip) {
                    // No VIP here: grant the prize fresh.
                    VipModel::create($steamId, $name, $group, $days, $sid);
                    $vipDid = true;
                    continue;
                }

                // Player already has VIP on this server. With "extend expiring
                // VIP" OFF, we leave existing VIP holders alone (classic skip).
                if (!$extendOn) {
                    continue;
                }

                // With it ON, extend/queue based on the current group:
                $row = VipModel::find($steamId, $sid);
                $currentGroup = $row ? trim((string) ($row['group'] ?? '')) : '';
                $currentExpires = $row ? (int) ($row['expires'] ?? 0) : 0;

                if ($currentGroup !== '' && strcasecmp($currentGroup, $group) === 0) {
                    // SAME group: add the prize days on top of the current
                    // expiry (extend). Permanent VIP (expires=0) stays permanent.
                    if ($currentExpires === 0) {
                        // Already permanent — nothing to add.
                        continue;
                    }
                    VipModel::extend($steamId, $sid, $days);
                    $vipDid = true;
                } else {
                    // DIFFERENT group: keep the current VIP until it expires,
                    // then apply the won group for the prize duration. VIPCore
                    // holds one row per server, so queue it (applied by
                    // Maintenance once the current VIP lapses). A permanent
                    // current VIP would never free up, so skip queuing then.
                    if ($currentExpires === 0) {
                        continue;
                    }
                    \App\Models\VipPendingModel::queue(
                        $steamId,
                        $name,
                        $sid,
                        $group,
                        $days,
                        (int) ($contest['id'] ?? 0)
                    );
                    $vipDid = true;
                }
            } catch (\Throwable $e) {
                error_log('[CS2Panel] contest VIP grant failed for ' . $steamId . ': ' . $e->getMessage());
            }
        }
        if ($vipDid) {
            $did = true;
            Database::run('UPDATE ' . self::WINNERS . ' SET vip_granted = 1 WHERE id = ?', [(int) $w['id']]);
            // Log the actual prize grant to the MAIN admin/audit Discord webhook
            // (same rich embed as a VIP add). Best-effort; never blocks the grant.
            try {
                $duration = $days > 0
                    ? \App\Core\Lang::t('discord.days_n', [$days])
                    : \App\Core\Lang::t('discord.permanent');
                \App\Core\Discord::notify(
                    'discord.contest_granted_title',
                    'discord.act_contest_granted',
                    'vip',
                    [
                        'contests.prize'  => $group . ' · ' . $duration,
                        'discord.contest' => (string) ($contest['title'] ?? ''),
                    ],
                    ['name' => (string) ($w['name'] ?? ''), 'steamid' => $steamId]
                );
            } catch (\Throwable $e) {
                error_log('[CS2Panel] contest grant Discord log failed: ' . $e->getMessage());
            }
        }
        return $did;
    }

    /** Mark a contest finished (winners drawn/processed). */
    public static function markFinished(int $contestId, bool $statsResetDone = false): void
    {
        if (!self::available()) {
            return;
        }
        Database::run(
            'UPDATE ' . self::TABLE . "
                SET status = 'finished', awarded_at = NOW(), stats_reset_done = ?
              WHERE id = ?",
            [$statsResetDone ? 1 : 0, $contestId]
        );
    }

    /** Mark a scheduled contest as started (goes live). */
    public static function markStarted(int $contestId): void
    {
        if (!self::available()) {
            return;
        }
        Database::run(
            'UPDATE ' . self::TABLE . " SET status = 'active', started = 1 WHERE id = ?",
            [$contestId]
        );
    }

                                                                        
    // Time-based (lazy) processing — no cron available
                                                                        

    /**
     * IDs of scheduled contests whose start time has passed and that haven't
     * started yet. These should go live now.
     * @return int[]
     */
    public static function dueToStartIds(): array
    {
        if (!self::available()) {
            return [];
        }
        $rows = Database::all(
            'SELECT id FROM ' . self::TABLE . " WHERE status = 'scheduled' AND started = 0 AND start_at IS NOT NULL AND start_at <= NOW() ORDER BY start_at ASC"
        );
        return array_map(static fn($r) => (int) $r['id'], $rows);
    }

    /**
     * IDs of active contests whose end time has passed and that still need
     * processing. Called on each Contests page load.
     * @return int[]
     */
    public static function dueContestIds(): array
    {
        if (!self::available()) {
            return [];
        }
        $rows = Database::all(
            'SELECT id FROM ' . self::TABLE . " WHERE status = 'active' AND ends_at <= NOW() ORDER BY ends_at ASC"
        );
        return array_map(static fn($r) => (int) $r['id'], $rows);
    }

    /**
     * Delete finished contests older than $days (history retention). 0 disables.
     * @return int contests removed
     */
    public static function purgeOldHistory(int $days): int
    {
        if (!self::available() || $days <= 0) {
            return 0;
        }
        $old = Database::all(
            'SELECT id FROM ' . self::TABLE . "
              WHERE status = 'finished'
                AND awarded_at IS NOT NULL
                AND awarded_at < (NOW() - INTERVAL ? DAY)",
            [$days]
        );
        $count = 0;
        foreach ($old as $row) {
            self::delete((int) $row['id']);
            $count++;
        }
        return $count;
    }

    /** Retention period in days (0 = keep forever). */
    public static function retentionDays(): int
    {
        return max(0, (int) Settings::get('contest_retention_days', 90));
    }
}
