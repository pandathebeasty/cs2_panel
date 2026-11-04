<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Settings;

/**
 * Store credits, backed by schwarper/cs2-store's `store_players` table.
 *
 * Plugin schema (table name configurable; default `store_players`):
 *   id              INT AUTO_INCREMENT
 *   SteamID         BIGINT UNSIGNED   -- SteamID64 (NOT the account_id VIPCore uses)
 *   PlayerName      VARCHAR(255)
 *   Credits         INT
 *   DateOfJoin      DATETIME
 *   DateOfLastJoin  DATETIME
 *   Vip             BOOLEAN
 *
 * IMPORTANT (online players): cs2-store saves a logged-in player's credits as a
 * DELTA (Credits + change) computed from the value it loaded at connect. If an
 * admin edits Credits in the DB while that player is online, the plugin can
 * overwrite the change on its next save. Panel edits are therefore reliable for
 * OFFLINE players; for online players they apply but may be re-synced by the
 * plugin. This is the standard limitation of editing plugin storage externally.
 */
final class StoreCreditsModel
{
    /** Connection key: the Store plugin's own database when configured, else the panel one. */
    private const DB = 'store';

    /** The cs2-store players table name (configurable to match the plugin cfg). */
    public static function table(): string
    {
        $t = trim((string) Settings::get('store_players_table', 'store_players'));
        return $t !== '' ? $t : 'store_players';
    }

    /** Feature is enabled when turned on in Settings AND the table exists. */
    public static function enabled(): bool
    {
        if (!Settings::bool('store_credits_enabled', false)) {
            return false;
        }
        return self::tableReady();
    }

    /** True when the configured credits table physically exists. */
    public static function tableReady(): bool
    {
        try {
            return Database::tableExists(self::table(), self::DB);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Whitelisted sort aliases -> ORDER BY expression (prevents SQL injection). */
    private const SORTS = [
        'credits' => 'Credits',
        'name'    => 'PlayerName',
        'joined'  => 'DateOfJoin',
        'lastjoin'=> 'DateOfLastJoin',
    ];

    private static function orderClause(string $sort, string $dir): string
    {
        $col = self::SORTS[$sort] ?? 'Credits';
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        // Expression only — Paginator::make prepends the ORDER BY keyword.
        // Stable tiebreaker by id so pagination is deterministic.
        return "`{$col}` {$dir}, id ASC";
    }

    /**
     * Paginated credits leaderboard. Rows expose steam_id (SteamID64 string),
     * name, credits, last_online so the view matches the other stats boards.
     */
    public static function leaderboard(int $page, int $perPage, string $sort = 'credits', string $dir = 'desc'): ?Paginator
    {
        if (!self::tableReady()) {
            return null;
        }
        $t = self::table();
        $order = self::orderClause($sort, $dir);
        $base = "SELECT CAST(`SteamID` AS CHAR) AS steam_id, `PlayerName` AS name,
                        `Credits` AS credits, `Vip` AS vip,
                        `DateOfLastJoin` AS last_online, `DateOfJoin` AS joined
                 FROM `{$t}`";
        $count = "SELECT COUNT(*) FROM `{$t}`";
        return Paginator::make($base, $count, [], $page, $perPage, $order, self::DB);
    }

    /** A single player's credit row, or null. $steamId64 is a SteamID64 string. */
    public static function find(string $steamId64): ?array
    {
        if (!self::tableReady() || !ctype_digit($steamId64)) {
            return null;
        }
        $t = self::table();
        return Database::one(
            "SELECT CAST(`SteamID` AS CHAR) AS steam_id, `PlayerName` AS name,
                    `Credits` AS credits, `DateOfJoin` AS joined, `DateOfLastJoin` AS last_online
             FROM `{$t}` WHERE `SteamID` = ? LIMIT 1",
            [$steamId64], self::DB
        );
    }

    /** Current credit balance for a player (0 when no row). */
    public static function get(string $steamId64): int
    {
        $row = self::find($steamId64);
        return $row ? (int) $row['credits'] : 0;
    }

    /**
     * Set a player's credits to an absolute value, creating the row if missing.
     * Credits are clamped to >= 0. Returns true on success.
     */
    public static function set(string $steamId64, int $credits, string $name = ''): bool
    {
        if (!self::tableReady() || !ctype_digit($steamId64)) {
            return false;
        }
        $credits = max(0, $credits);
        $t = self::table();
        try {
            $existing = self::find($steamId64);
            if ($existing !== null) {
                Database::run("UPDATE `{$t}` SET `Credits` = ? WHERE `SteamID` = ?", [$credits, $steamId64], self::DB);
            } else {
                // New row: mirror the plugin's column set so a later plugin load
                // doesn't choke on NULLs it doesn't expect.
                Database::run(
                    "INSERT INTO `{$t}` (`SteamID`, `PlayerName`, `Credits`, `DateOfJoin`, `DateOfLastJoin`, `Vip`)
                     VALUES (?, ?, ?, NOW(), NOW(), 0)",
                    [$steamId64, mb_substr($name !== '' ? $name : 'Player', 0, 255), $credits], self::DB
                );
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[CS2Panel] credits set failed for ' . $steamId64 . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Add (or, with a negative amount, subtract) credits. Creates the row if
     * missing. Balance never goes below 0. Returns the new balance or null on
     * failure. Used by admin +/- and by store/contest grants.
     */
    public static function add(string $steamId64, int $amount, string $name = ''): ?int
    {
        if (!self::tableReady() || !ctype_digit($steamId64)) {
            return null;
        }
        $t = self::table();
        try {
            $existing = self::find($steamId64);
            if ($existing !== null) {
                // GREATEST(...,0) mirrors the plugin's own non-negative clamp.
                Database::run(
                    "UPDATE `{$t}` SET `Credits` = GREATEST(`Credits` + ?, 0) WHERE `SteamID` = ?",
                    [$amount, $steamId64], self::DB
                );
                if ($name !== '') {
                    Database::run("UPDATE `{$t}` SET `PlayerName` = ? WHERE `SteamID` = ?",
                        [mb_substr($name, 0, 255), $steamId64], self::DB);
                }
            } else {
                Database::run(
                    "INSERT INTO `{$t}` (`SteamID`, `PlayerName`, `Credits`, `DateOfJoin`, `DateOfLastJoin`, `Vip`)
                     VALUES (?, ?, ?, NOW(), NOW(), 0)",
                    [$steamId64, mb_substr($name !== '' ? $name : 'Player', 0, 255), max(0, $amount)], self::DB
                );
            }
            return self::get($steamId64);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] credits add failed for ' . $steamId64 . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Reset one player's credits to 0. */
    public static function reset(string $steamId64): bool
    {
        return self::set($steamId64, 0);
    }

    /**
     * Reset ALL players' credits to 0. Returns affected row count. Root-only
     * callers should take a DB backup first (handled in the controller).
     */
    public static function resetAll(): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $t = self::table();
        $before = (int) Database::scalar("SELECT COUNT(*) FROM `{$t}` WHERE `Credits` <> 0", [], self::DB);
        Database::run("UPDATE `{$t}` SET `Credits` = 0", [], self::DB);
        return $before;
    }

    /** Totals for the dashboard/summary: player count + total credits in play. */
    public static function totals(): array
    {
        if (!self::tableReady()) {
            return ['players' => 0, 'credits' => 0];
        }
        $t = self::table();
        $row = Database::one("SELECT COUNT(*) AS players, COALESCE(SUM(`Credits`),0) AS credits FROM `{$t}`", [], self::DB);
        return [
            'players' => (int) ($row['players'] ?? 0),
            'credits' => (int) ($row['credits'] ?? 0),
        ];
    }
}
