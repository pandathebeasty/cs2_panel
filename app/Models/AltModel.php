<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

/**
 * Multi-account / ban-evasion detection based on the IP history CS2-SimpleAdmin
 * records in {prefix}players_ips (steamid, name, address, used_at), plus the IP
 * stored on bans. Read-only: it never changes plugin data.
 */
final class AltModel
{
    private const ACTIVE_BAN = "UPPER(b.status) = 'ACTIVE' AND (b.duration = 0 OR b.ends IS NULL OR b.ends > NOW())";

    private static function p(): string
    {
        return Config::get('sa_prefix', 'sa_');
    }

    /** The name column was added in a later SimpleAdmin migration. */
    private static function nameCol(string $alias): string
    {
        static $has = null;
        $has ??= Database::columnExists(self::p() . 'players_ips', 'name');
        return $has ? $alias . '.name' : 'NULL';
    }

    /** Newer SimpleAdmin versions store players_ips.address as INET_ATON(). */
    private static function numericAddress(): bool
    {
        static $numeric = null;
        if ($numeric === null) {
            try {
                $type = Database::scalar(
                    "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'address'",
                    [self::p() . 'players_ips']
                );
                $numeric = in_array(strtolower((string) $type), ['int', 'bigint', 'mediumint', 'decimal'], true);
            } catch (\Throwable $e) {
                $numeric = false;
            }
        }
        return $numeric;
    }

    /** players_ips.address as dotted text. */
    private static function addr(string $alias): string
    {
        return self::numericAddress() ? "INET_NTOA({$alias}.address)" : "{$alias}.address";
    }

    /** SQL matching players_ips.address against a text IP expression. */
    private static function addrEquals(string $alias, string $textExpr): string
    {
        return self::numericAddress() ? "{$alias}.address = INET_ATON({$textExpr})" : "{$alias}.address = {$textExpr}";
    }

    /** Dotted IPv4 for display, whether stored as text or as an integer. */
    public static function ip(mixed $value): string
    {
        $v = trim((string) $value);
        if ($v !== '' && ctype_digit($v) && (int) $v <= 4294967295) {
            return long2ip((int) $v);
        }
        return $v;
    }

    public static function available(): bool
    {
        return Database::tableExists(self::p() . 'players_ips');
    }

    /** @return array<int,array{address:string,used_at:?string}> */
    public static function ipsFor(string $steamId): array
    {
        $p = self::p();
        $ips = [];
        if (self::available()) {
            foreach (Database::all("SELECT address, used_at FROM {$p}players_ips WHERE steamid = ? ORDER BY used_at DESC LIMIT 50", [$steamId]) as $r) {
                $ip = self::ip($r['address']);
                $ips[$ip] = ['address' => $ip, 'used_at' => $r['used_at']];
            }
        }
        foreach (Database::all("SELECT DISTINCT player_ip FROM {$p}bans WHERE player_steamid = ? AND player_ip IS NOT NULL AND player_ip <> ''", [$steamId]) as $r) {
            $ip = self::ip($r['player_ip']);
            $ips[$ip] ??= ['address' => $ip, 'used_at' => null];
        }
        return array_values($ips);
    }

    /**
     * Other accounts that used one of this player's IPs.
     * @return array<int,array<string,mixed>> steamid, name, shared (IPs), last_seen, banned
     */
    public static function forPlayer(string $steamId): array
    {
        if (!self::available()) {
            return [];
        }
        $ips = array_column(self::ipsFor($steamId), 'address');
        if (!$ips) {
            return [];
        }
        $p = self::p();
        if (self::numericAddress()) {
            $ips = array_values(array_filter(array_map(static fn($ip) => ($n = ip2long($ip)) === false ? null : sprintf('%u', $n), $ips)));
            if (!$ips) {
                return [];
            }
        }
        $ph = implode(',', array_fill(0, count($ips), '?'));
        $rows = Database::all(
            "SELECT pi.steamid, MAX(" . self::nameCol('pi') . ") AS name, GROUP_CONCAT(DISTINCT " . self::addr('pi') . " ORDER BY pi.address SEPARATOR ', ') AS shared,
                    MAX(pi.used_at) AS last_seen,
                    (SELECT COUNT(*) FROM {$p}bans b WHERE b.player_steamid = pi.steamid AND " . self::ACTIVE_BAN . ") AS banned
             FROM {$p}players_ips pi
             WHERE pi.address IN ({$ph}) AND pi.steamid <> ?
             GROUP BY pi.steamid
             ORDER BY last_seen DESC
             LIMIT 100",
            array_merge($ips, [$steamId])
        );
        foreach ($rows as &$r) {
            $r['steamid'] = (string) $r['steamid'];
            $r['banned'] = (int) $r['banned'] > 0;
        }
        unset($r);
        return $rows;
    }

    /**
     * Possible ban evasion: accounts without an active ban that connected from
     * an IP linked to an actively banned account (via IP history or ban IP),
     * after that ban was issued.
     * @return array<int,array<string,mixed>>
     */
    public static function evasion(int $limit = 200): array
    {
        if (!self::available()) {
            return [];
        }
        $p = self::p();
        $limit = max(1, min(500, $limit));
        $notBanned = "NOT EXISTS (SELECT 1 FROM {$p}bans b2 WHERE b2.player_steamid = pi.steamid AND UPPER(b2.status) = 'ACTIVE'
                       AND (b2.duration = 0 OR b2.ends IS NULL OR b2.ends > NOW()))";
        $rows = Database::all(
            "SELECT * FROM (
                SELECT pi.steamid, " . self::nameCol('pi') . " AS name, " . self::addr('pi') . " AS address, pi.used_at, b.id AS ban_id, b.player_steamid AS banned_steamid,
                       b.player_name AS banned_name, b.reason, b.created AS ban_created
                FROM {$p}bans b
                JOIN {$p}players_ips bi ON bi.steamid = b.player_steamid
                JOIN {$p}players_ips pi ON pi.address = bi.address AND pi.steamid <> b.player_steamid
                WHERE " . self::ACTIVE_BAN . " AND pi.used_at >= b.created AND {$notBanned}
                UNION
                SELECT pi.steamid, " . self::nameCol('pi') . " AS name, " . self::addr('pi') . " AS address, pi.used_at, b.id AS ban_id, b.player_steamid AS banned_steamid,
                       b.player_name AS banned_name, b.reason, b.created AS ban_created
                FROM {$p}bans b
                JOIN {$p}players_ips pi ON " . self::addrEquals('pi', 'b.player_ip') . "
                WHERE " . self::ACTIVE_BAN . " AND b.player_ip IS NOT NULL AND b.player_ip <> ''
                  AND (b.player_steamid IS NULL OR pi.steamid <> b.player_steamid)
                  AND pi.used_at >= b.created AND {$notBanned}
             ) x
             ORDER BY used_at DESC
             LIMIT {$limit}"
        );
        // One row per suspect account + banned account pair.
        $out = [];
        foreach ($rows as $r) {
            $key = $r['steamid'] . '|' . $r['ban_id'];
            if (!isset($out[$key])) {
                $r['steamid'] = (string) $r['steamid'];
                $r['banned_steamid'] = $r['banned_steamid'] !== null ? (string) $r['banned_steamid'] : null;
                $out[$key] = $r;
            }
        }
        return array_values($out);
    }
}
