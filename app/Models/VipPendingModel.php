<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\DevMode;

/**
 * Queued ("pending") VIP grants for contest prizes.
 *
 * VIPCore stores exactly ONE VIP row per (account_id, sid), so a player who
 * wins a DIFFERENT VIP group than the one they already own cannot hold both at
 * once. The contest rule is: keep the existing VIP until it expires, THEN apply
 * the won group for the prize duration. We can't schedule that inside VIPCore,
 * so the won grant is parked here and applied by Maintenance once the player's
 * current VIP on that server has lapsed.
 *
 * Columns:
 *   id          auto id
 *   steamid     SteamID64 of the winner
 *   name        display name to stamp on the VIP row
 *   sid         VIPCore server id the grant targets
 *   group       the won VIP group to apply
 *   days        prize duration in days (0 = permanent)
 *   contest_id  the contest this prize came from (for logging/traceability)
 *   created_at  unix timestamp the pending grant was recorded
 */
final class VipPendingModel
{
    private const TABLE = 'panel_vip_pending';

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
                    name VARCHAR(128) NULL,
                    sid INT NOT NULL,
                    `group` VARCHAR(64) NOT NULL,
                    days INT NOT NULL DEFAULT 0,
                    contest_id INT NULL,
                    created_at INT UNSIGNED NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_player_server (steamid, sid),
                    KEY idx_steam (steamid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] VipPending ensureTable failed: ' . $e->getMessage());
        }
    }

    private static function available(): bool
    {
        if (DevMode::enabled()) {
            return false;
        }
        self::ensureTable();
        return Database::tableExists(self::TABLE);
    }

    /**
     * Queue a won VIP to be applied on $sid once the player's current VIP there
     * expires. One pending grant per (steamid, sid): a newer win replaces an
     * older still-unapplied one for the same server.
     */
    public static function queue(string $steamId64, string $name, int $sid, string $group, int $days, int $contestId): void
    {
        if (!self::available() || $steamId64 === '' || $group === '') {
            return;
        }
        try {
            Database::run(
                'INSERT INTO ' . self::TABLE . ' (steamid, name, sid, `group`, days, contest_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
                 . Database::onDuplicateUpdate(['name', 'group', 'days', 'contest_id', 'created_at']),
                [$steamId64, $name !== '' ? $name : 'VIP', $sid, $group, $days, $contestId, time()]
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] VipPending queue failed for ' . $steamId64 . ': ' . $e->getMessage());
        }
    }

    /**
     * Apply every pending grant whose player no longer has a (real) VIP on the
     * target server — i.e. the previous VIP has expired. Returns how many were
     * applied. Safe no-op when the table/VIPCore is unavailable.
     */
    public static function applyDue(): int
    {
        if (!self::available() || !VipModel::enabled()) {
            return 0;
        }
        try {
            $rows = Database::all('SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC');
        } catch (\Throwable $e) {
            return 0;
        }
        $applied = 0;
        foreach ($rows as $r) {
            $steamId = (string) ($r['steamid'] ?? '');
            $sid     = (int) ($r['sid'] ?? 0);
            if ($steamId === '' || $sid <= 0) {
                self::deleteRow((int) $r['id']);
                continue;
            }
            try {
                // Only apply once the current VIP on this server has lapsed.
                if (VipModel::hasRealVipOnServer($steamId, $sid)) {
                    continue;
                }
                VipModel::create(
                    $steamId,
                    (string) ($r['name'] ?? 'VIP'),
                    (string) $r['group'],
                    (int) ($r['days'] ?? 0),
                    $sid
                );
                self::deleteRow((int) $r['id']);
                $applied++;
            } catch (\Throwable $e) {
                error_log('[CS2Panel] VipPending apply failed for ' . $steamId . ': ' . $e->getMessage());
            }
        }
        if ($applied > 0) {
            try {
                \App\Core\ServerSync::refreshVip();
            } catch (\Throwable $e) {
                // best-effort
            }
        }
        return $applied;
    }

    private static function deleteRow(int $id): void
    {
        try {
            Database::run('DELETE FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
