<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Steam;

/**
 * VIPCore integration (partiusfabaa/cs2-VIPCore).
 * The vip_users table uses account_id (SteamID64 - 76561197960265728).
 * The panel converts to/from the full SteamID64.
 */
final class VipModel
{
    public static function enabled(): bool
    {
        if (!Config::get('vipcore.enabled', false)) {
            return false;
        }
        return Database::tableExists(Config::get('vipcore.users_table', 'vip_users'));
    }

    private static function table(): string
    {
        return Config::get('vipcore.users_table', 'vip_users');
    }

    private static function serversTable(): string
    {
        return Config::get('vipcore.servers_table', 'vip_servers');
    }

    /**
     * Groups to hide from the panel (e.g. internal/among-us groups you don't
     * want listed under active VIPs). Comma-separated in settings.
     * @return string[]
     */
    private static function ignoredGroups(): array
    {
        $raw = (string) \App\Core\Settings::get('vip_ignored_groups', '');
        $list = array_filter(array_map('trim', explode(',', $raw)), static fn($g) => $g !== '');
        return array_values($list);
    }

    /**
     * The VIP groups the admin defined in Settings (one per line or comma-
     * separated). These populate the group dropdown when adding/editing a VIP,
     * so a group can be chosen before any VIP has ever used it.
     * @return string[]
     */
    public static function configuredGroups(): array
    {
        $raw = (string) \App\Core\Settings::get('vip_groups', '');
        // Accept both newlines and commas as separators.
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $list = array_filter(array_map('trim', $parts), static fn($g) => $g !== '');
        // De-duplicate while preserving order.
        return array_values(array_unique($list));
    }

    public static function paginate(array $filters, int $page, int $perPage): Paginator
    {
        $t = self::table();
        $st = self::serversTable();
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(v.name LIKE ? OR v.`group` LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;

            $steamId = Steam::toSteamId64Any((string) $filters['search']);
            if ($steamId !== null) {
                $where[count($where) - 1] = '(v.name LIKE ? OR v.`group` LIKE ? OR v.account_id = ?)';
                $params[] = Steam::toAccountId($steamId);
            }
        }
        if (!empty($filters['group'])) {
            $where[] = 'v.`group` = ?';
            $params[] = $filters['group'];
        }

        // Hide ignored groups (unless the user explicitly filtered by one of them).
        $ignored = self::ignoredGroups();
        if ($ignored && empty($filters['group'])) {
            $placeholders = implode(', ', array_fill(0, count($ignored), '?'));
            $where[] = "v.`group` NOT IN ({$placeholders})";
            foreach ($ignored as $g) {
                $params[] = $g;
            }
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Optional JOIN with vip_servers to show which server the VIP is on.
        $base = "SELECT v.*, srv.serverIp AS server_ip, srv.port AS server_port
                 FROM {$t} v
                 LEFT JOIN {$st} srv ON srv.serverId = v.sid
                 {$whereSql}";
        $count = "SELECT COUNT(*) FROM {$t} v {$whereSql}";

        $pg = Paginator::make($base, $count, $params, $page, $perPage, 'v.expires = 0 DESC, v.expires DESC');

        // Enrich each row with steamid64 and expiry state.
        foreach ($pg->items as &$row) {
            $row['steamid64']   = Steam::toSteamId64((int) $row['account_id']);
            $row['is_permanent'] = ((int) $row['expires']) === 0;
            $row['is_expired']  = !$row['is_permanent'] && ((int) $row['expires']) < time();
        }
        unset($row);

        return $pg;
    }

    /** True if a VIP row already exists for this SteamID64 + server. */
    public static function exists(string $steamId64, int $sid): bool
    {
        $t = self::table();
        $accountId = Steam::toAccountId($steamId64);
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM {$t} WHERE account_id = ? AND sid = ?",
            [$accountId, $sid]
        ) > 0;
    }

    /**
     * True if the player has a NON-IGNORED VIP row on this server. Used by the
     * contest award so an ignored group (e.g. "test") on that server doesn't
     * block granting the real prize — while still avoiding duplicating a real VIP.
     */
    public static function hasRealVipOnServer(string $steamId64, int $sid): bool
    {
        $t = self::table();
        $accountId = Steam::toAccountId($steamId64);

        $sql = "SELECT COUNT(*) FROM {$t} WHERE account_id = ? AND sid = ?";
        $params = [$accountId, $sid];

        $ignored = self::ignoredGroups();
        if ($ignored) {
            $ph = implode(', ', array_fill(0, count($ignored), '?'));
            $sql .= " AND `group` NOT IN ({$ph})";
            foreach ($ignored as $g) {
                $params[] = $g;
            }
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /**
     * True if the player has an ACTIVE VIP on ANY server (permanent, i.e.
     * expires = 0, or not yet expired). Used to skip contest winners who
     * already own VIP. Returns false when VIPCore isn't available.
     *
     * Ignored groups (Settings) do NOT count: a player whose only active VIP is
     * an ignored group (e.g. a "test" group) is treated as having no VIP, so
     * they can still win a contest. Only a real, non-ignored VIP excludes them.
     */
    public static function hasActiveVip(string $steamId64): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $t = self::table();
        $accountId = Steam::toAccountId($steamId64);

        $sql = "SELECT COUNT(*) FROM {$t} WHERE account_id = ? AND (expires = 0 OR expires > ?)";
        $params = [$accountId, time()];

        // Don't let an ignored group (e.g. "test") count as owning VIP.
        $ignored = self::ignoredGroups();
        if ($ignored) {
            $ph = implode(', ', array_fill(0, count($ignored), '?'));
            $sql .= " AND `group` NOT IN ({$ph})";
            foreach ($ignored as $g) {
                $params[] = $g;
            }
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /** True if the player owns a permanent (expires = 0), non-ignored VIP on any server. */
    public static function hasPermanentVip(string $steamId64): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $t = self::table();
        $sql = "SELECT COUNT(*) FROM {$t} WHERE account_id = ? AND expires = 0";
        $params = [Steam::toAccountId($steamId64)];

        $ignored = self::ignoredGroups();
        if ($ignored) {
            $ph = implode(', ', array_fill(0, count($ignored), '?'));
            $sql .= " AND `group` NOT IN ({$ph})";
            foreach ($ignored as $g) {
                $params[] = $g;
            }
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /**
     * True when the player's active VIP is TEMPORARY (not permanent) and the
     * soonest expiry falls within $seconds from now. Ignored groups don't
     * count. Permanent VIP (expires = 0) always returns false.
     *
     * Used by contests: a top player whose VIP is about to lapse should still
     * win so their VIP gets renewed, instead of being skipped as "already VIP".
     */
    public static function expiresWithin(string $steamId64, int $seconds): bool
    {
        if (!self::enabled() || $seconds <= 0) {
            return false;
        }
        $t = self::table();
        $now = time();
        // Only temporary rows (expires > 0) that are still active but end soon.
        $sql = "SELECT COUNT(*) FROM {$t}
                WHERE account_id = ? AND expires > ? AND expires <= ?";
        $params = [Steam::toAccountId($steamId64), $now, $now + $seconds];

        $ignored = self::ignoredGroups();
        if ($ignored) {
            $ph = implode(', ', array_fill(0, count($ignored), '?'));
            $sql .= " AND `group` NOT IN ({$ph})";
            foreach ($ignored as $g) {
                $params[] = $g;
            }
        }
        try {
            return (int) Database::scalar($sql, $params) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * True when the player has an active VIP on the given server that is
     * TEMPORARY and expires within $seconds. Per-server variant of
     * expiresWithin(), used when deciding whether to renew on a specific server.
     */
    public static function expiresWithinOnServer(string $steamId64, int $sid, int $seconds): bool
    {
        if (!self::enabled() || $seconds <= 0) {
            return false;
        }
        $t = self::table();
        $now = time();
        $sql = "SELECT COUNT(*) FROM {$t}
                WHERE account_id = ? AND sid = ? AND expires > ? AND expires <= ?";
        $params = [Steam::toAccountId($steamId64), $sid, $now, $now + $seconds];
        $ignored = self::ignoredGroups();
        if ($ignored) {
            $ph = implode(', ', array_fill(0, count($ignored), '?'));
            $sql .= " AND `group` NOT IN ({$ph})";
            foreach ($ignored as $g) {
                $params[] = $g;
            }
        }
        try {
            return (int) Database::scalar($sql, $params) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Name of the player's active (non-ignored) VIP group, '' when they have
     * none. Used to tell the buyer exactly which VIP they already own.
     */
    public static function activeVipGroup(string $steamId64): string
    {
        if (!self::enabled()) {
            return '';
        }
        $t = self::table();
        $sql = "SELECT `group` FROM {$t} WHERE account_id = ? AND (expires = 0 OR expires > ?)";
        $params = [Steam::toAccountId($steamId64), time()];

        $ignored = self::ignoredGroups();
        if ($ignored) {
            $ph = implode(', ', array_fill(0, count($ignored), '?'));
            $sql .= " AND `group` NOT IN ({$ph})";
            foreach ($ignored as $g) {
                $params[] = $g;
            }
        }
        $sql .= ' ORDER BY expires = 0 DESC, expires DESC LIMIT 1';

        try {
            return (string) (Database::scalar($sql, $params) ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * VIPCore's configured duration unit (0 seconds, 1 minutes, 2 hours,
     * 3 days) — mirrors the plugin's own config so the store can convert.
     */
    public static function timeMode(): int
    {
        $mode = (int) \App\Core\Settings::get('vip_time_mode', 3);
        return $mode >= 0 && $mode <= 3 ? $mode : 3;
    }

                                                                               
    public static function unitsToDays(int $value): int
    {
        return match (self::timeMode()) {
            0 => (int) floor($value / 86400),
            1 => (int) floor($value / 1440),
            2 => (int) floor($value / 24),
            default => $value,
        };
    }

                                                       
    public static function daysToUnits(int $days): int
    {
        return match (self::timeMode()) {
            0 => $days * 86400,
            1 => $days * 1440,
            2 => $days * 24,
            default => $days,
        };
    }

    /**
     * Add a NEW VIP (does NOT overwrite an existing one — the controller checks
     * exists() first and errors if it does). duration in days (0 = permanent).
     * account_id = SteamID64 - offset. sid = the VIPCore server id.
     */
    public static function create(string $steamId64, string $name, string $group, int $days, int $sid): void
    {
        $t = self::table();
        $accountId = Steam::toAccountId($steamId64);
        $expires = $days > 0 ? (time() + $days * 86400) : 0;

        // Upsert: a stale row for this (account_id, sid) — e.g. an ignored-group
        // test/staff VIP, or a long-expired entry — must not silently block the
        // grant with a duplicate-key error; it should just be overwritten.
        Database::run(
            "INSERT INTO {$t} (account_id, name, lastvisit, sid, `group`, expires)
             VALUES (?, ?, ?, ?, ?, ?)"
             . Database::onDuplicateUpdate(['name', 'lastvisit', 'group', 'expires']),
            [$accountId, $name !== '' ? $name : 'VIP', time(), $sid, $group, $expires]
        );
    }

    /** Extend an existing VIP by N days. */
    public static function extend(string $steamId64, int $sid, int $days): void
    {
        $t = self::table();
        $accountId = Steam::toAccountId($steamId64);
        $row = Database::one(
            "SELECT expires FROM {$t} WHERE account_id = ? AND sid = ? LIMIT 1",
            [$accountId, $sid]
        );
        if (!$row) {
            return;
        }
        // base: max(now, current expiry) if not permanent
        $current = (int) $row['expires'];
        if ($current === 0) {
            return; // already permanent
        }
        $base = max(time(), $current);
        $newExpires = $days <= 0 ? 0 : $base + $days * 86400;
        Database::run(
            "UPDATE {$t} SET expires = ? WHERE account_id = ? AND sid = ?",
            [$newExpires, $accountId, $sid]
        );
    }

    /**
     * Edit an existing VIP: change name, group and expiry directly.
     * $expires is an absolute UNIX timestamp (0 = permanent).
     */
    public static function update(string $steamId64, int $sid, string $name, string $group, int $expires): void
    {
        $t = self::table();
        $accountId = Steam::toAccountId($steamId64);
        Database::run(
            "UPDATE {$t} SET name = ?, `group` = ?, expires = ? WHERE account_id = ? AND sid = ?",
            [$name !== '' ? $name : 'VIP', $group !== '' ? $group : 'VIP', $expires, $accountId, $sid]
        );
    }

    /** Fetch a single VIP row by SteamID64 + server id (for the edit form). */
    public static function find(string $steamId64, int $sid): ?array
    {
        $t = self::table();
        $accountId = Steam::toAccountId($steamId64);
        $row = Database::one(
            "SELECT * FROM {$t} WHERE account_id = ? AND sid = ? LIMIT 1",
            [$accountId, $sid]
        );
        return $row ?: null;
    }

    public static function remove(string $steamId64, int $sid): void
    {
        $t = self::table();
        $accountId = Steam::toAccountId($steamId64);
        Database::run("DELETE FROM {$t} WHERE account_id = ? AND sid = ?", [$accountId, $sid]);
    }

    /** Available VIP servers (for the sid dropdown). */
    public static function servers(): array
    {
        $st = self::serversTable();
        if (!Database::tableExists($st)) {
            return [];
        }
        return Database::all("SELECT serverId, serverIp, port FROM {$st} ORDER BY serverId ASC");
    }

    /** Distinct VIP groups (for filters), excluding the ignored ones. */
    public static function groups(): array
    {
        $t = self::table();
        $rows = Database::all("SELECT DISTINCT `group` FROM {$t} ORDER BY `group` ASC");
        $groups = array_column($rows, 'group');
        $ignored = self::ignoredGroups();
        if ($ignored) {
            $groups = array_values(array_filter($groups, static fn($g) => !in_array($g, $ignored, true)));
        }
        return $groups;
    }

    public static function stats(): array
    {
        if (!self::enabled()) {
            return ['total' => 0, 'active' => 0];
        }
        $t = self::table();

                                                                             
        // count matches what's actually shown.
        $ignored = self::ignoredGroups();
        $ignoreSql = '';
        $ignoreParams = [];
        if ($ignored) {
            $placeholders = implode(', ', array_fill(0, count($ignored), '?'));
            $ignoreSql = " AND `group` NOT IN ({$placeholders})";
            $ignoreParams = $ignored;
        }

        return [
            'total'  => (int) Database::scalar(
                "SELECT COUNT(*) FROM {$t} WHERE 1=1{$ignoreSql}",
                $ignoreParams
            ),
            'active' => (int) Database::scalar(
                "SELECT COUNT(*) FROM {$t} WHERE (expires = 0 OR expires > ?){$ignoreSql}",
                array_merge([time()], $ignoreParams)
            ),
        ];
    }
}
