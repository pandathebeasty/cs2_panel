<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\DevMode;

/**
 * Player activity history (panel_activity table).
 *
 * The panel has no live connect feed from the game server, so it builds a real
 * entry history by SAMPLING: every maintenance tick (cron, ~5 min) records the
 * players who are newly active since the previous sample. Each recorded row is
 * one "entry" (a player appearing who wasn't counted in the recent window).
 *
 * The dashboard activity graph then counts these rows per hour — a genuine
 * accumulated history, as opposed to a snapshot of current lastseen values.
 *
 * Columns:
 *   id       auto id
 *   steamid  SteamID64 of the player
 *   seen_at  unix timestamp the entry was recorded
 */
final class ActivityModel
{
    private const TABLE = 'panel_activity';

    /** How recent a player's lastseen must be to count as "active" (seconds). */
    private const ACTIVE_WINDOW = 600;      // 10 minutes (> the 5-min cron gap)

    /** De-dupe window: don't re-count a steamid already recorded this recently. */
    private const DEDUPE_WINDOW = 1800;     // 30 minutes

    /** How long entry history is kept (seconds). Older rows are pruned. */
    private const RETENTION = 7 * 86400;    // 7 days

    /** Create the table on demand (no manual migration needed). */
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if (DevMode::enabled()) {
            return;
        }
        if (Database::tableExists(self::TABLE) && !Database::schemaRepair()) {
            return;
        }
        try {
            Database::createTable(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    steamid VARCHAR(32) NOT NULL,
                    seen_at INT UNSIGNED NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_seen (seen_at),
                    KEY idx_steam_seen (steamid, seen_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] activity table create failed: ' . $e->getMessage());
        }
    }

    /**
     * Take one activity sample: record players who became active since the last
     * sample. A player already recorded within DEDUPE_WINDOW is not counted
     * again (so a continuously-online player is one entry, not one per tick).
     * Also prunes rows older than RETENTION. Best-effort; never throws.
     *
     * @return int number of new entries recorded this sample
     */
    public static function sample(): int
    {
        self::ensureTable();
        if (DevMode::enabled()) {
            return 0;
        }

        $now = time();
        // Prune old history first (cheap, keeps the table bounded).
        try {
            Database::run('DELETE FROM ' . self::TABLE . ' WHERE seen_at < ?', [$now - self::RETENTION]);
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Currently-active players (lastseen within the active window).
        $activeIds = StatsBackend::activeSince($now - self::ACTIVE_WINDOW);
        if (!$activeIds) {
            return 0;
        }

        // Which of those were already recorded recently (within de-dupe window)?
        $recent = [];
        try {
            $ph = implode(',', array_fill(0, count($activeIds), '?'));
            $params = array_merge($activeIds, [$now - self::DEDUPE_WINDOW]);
            $rows = Database::all(
                'SELECT DISTINCT steamid FROM ' . self::TABLE . "
                 WHERE steamid IN ({$ph}) AND seen_at >= ?",
                $params
            );
            foreach ($rows as $r) {
                $recent[(string) $r['steamid']] = true;
            }
        } catch (\Throwable $e) {
            // If the lookup fails, fall back to recording all (safe over-count
            // is better than losing data); recent stays empty.
        }

        $new = array_values(array_filter($activeIds, static fn($id) => !isset($recent[(string) $id])));
        if (!$new) {
            return 0;
        }

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('INSERT INTO ' . self::TABLE . ' (steamid, seen_at) VALUES (?, ?)');
            foreach ($new as $id) {
                $stmt->execute([mb_substr((string) $id, 0, 32), $now]);
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] activity sample insert failed: ' . $e->getMessage());
            return 0;
        }
        return count($new);
    }

    /**
     * Entries per hour over the last $hours, oldest -> newest.
     * Each bucket: ['label' => 'HH:00', 'count' => n].
     *
     * @return array<int, array{label:string, count:int}>
     */
    public static function entriesByHour(int $hours = 6): array
    {
        $hours = max(1, min(48, $hours));
        $now = time();
        $hourStart = $now - ($now % 3600);
        $buckets = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $ts = $hourStart - $i * 3600;
            $buckets[] = ['ts' => $ts, 'label' => date('H:00', $ts), 'count' => 0];
        }

        self::ensureTable();
        if (!DevMode::enabled()) {
            $from = (int) $buckets[0]['ts'];
            try {
                $rows = Database::all(
                    'SELECT FLOOR((seen_at - ?) / 3600) AS bucket, COUNT(*) AS c
                     FROM ' . self::TABLE . '
                     WHERE seen_at >= ?
                     GROUP BY bucket',
                    [$from, $from]
                );
                foreach ($rows as $r) {
                    $b = (int) $r['bucket'];
                    if ($b >= 0 && $b < count($buckets)) {
                        $buckets[$b]['count'] = (int) $r['c'];
                    }
                }
            } catch (\Throwable $e) {
                // return empty buckets
            }
        }
        return array_map(static fn($b) => ['label' => $b['label'], 'count' => $b['count']], $buckets);
    }
}
