<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Steam;
use App\Core\Url;
use App\Models\AdminModel;
use App\Models\AltModel;
use App\Models\VipModel;

/**
 * Staff tools around a single player: the aggregated player profile, the
 * global quick search used by the command palette and the ban-evasion list.
 */
final class PlayerController extends Controller
{
    private static function p(): string
    {
        return Config::get('sa_prefix', 'sa_');
    }

    /** Run a read query, returning [] when the plugin table is missing. */
    private static function safeAll(string $sql, array $params = []): array
    {
        try {
            return Database::all($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function show(string $steamid): void
    {
        Auth::requireModerate();
        $steamId = Steam::toSteamId64Any($steamid);
        if ($steamId === null) {
            http_response_code(404);
            $this->view('errors/404');
            return;
        }
        $p = self::p();
        $bans = self::safeAll("SELECT * FROM {$p}bans WHERE player_steamid = ? ORDER BY id DESC LIMIT 50", [$steamId]);
        $mutes = self::safeAll("SELECT * FROM {$p}mutes WHERE player_steamid = ? ORDER BY id DESC LIMIT 50", [$steamId]);
        $warns = self::safeAll("SELECT * FROM {$p}warns WHERE player_steamid = ? ORDER BY id DESC LIMIT 50", [$steamId]);

        $vip = [];
        if (VipModel::enabled()) {
            $vip = self::safeAll(
                'SELECT * FROM `' . Config::get('vipcore.users_table', 'vip_users') . '` WHERE account_id = ?',
                [Steam::toAccountId($steamId)]
            );
        }
        $audit = Database::tableExists('panel_logs')
            ? Database::all('SELECT * FROM panel_logs WHERE target = ? OR actor_steamid = ? ORDER BY id DESC LIMIT 25', [$steamId, $steamId])
            : [];

        $name = Steam::personaName($steamId);
        if ($name === '') {
            $name = (string) (Logger::resolveNames([$steamId])[$steamId] ?? '');
        }
        $altsAvailable = AltModel::available();
        $alts = [];
        $ips = [];
        try {
            $ips = AltModel::ipsFor($steamId);
            $alts = $altsAvailable ? AltModel::forPlayer($steamId) : [];
        } catch (\Throwable $e) {
            error_log('[CS2Panel] alt lookup failed: ' . $e->getMessage());
        }
        $avatars = Steam::avatars(array_merge([$steamId], array_column($alts, 'steamid')));

        $now = time();
        $isActive = static function (array $row) use ($now): bool {
            if (strtoupper((string) ($row['status'] ?? '')) !== 'ACTIVE') {
                return false;
            }
            $ends = $row['ends'] ?? null;
            return (int) ($row['duration'] ?? 0) === 0 || $ends === null || strtotime((string) $ends) > $now;
        };

        $this->view('players/show', [
            'steamId'       => $steamId,
            'name'          => $name,
            'avatar'        => $avatars[$steamId] ?? Steam::avatarPlaceholder(),
            'avatars'       => $avatars,
            'admin'         => AdminModel::findBySteamId($steamId),
            'bans'          => $bans,
            'mutes'         => $mutes,
            'warns'         => $warns,
            'vip'           => $vip,
            'alts'          => $alts,
            'ips'           => $ips,
            'altsAvailable' => $altsAvailable,
            'audit'         => $audit,
            'activeBans'    => count(array_filter($bans, $isActive)),
            'activeMutes'   => count(array_filter($mutes, $isActive)),
            'activeWarns'   => count(array_filter($warns, $isActive)),
        ]);
    }

    /** Possible ban evasion accounts. */
    public function evasion(): void
    {
        Auth::requireModerate();
        $rows = [];
        $error = false;
        try {
            $rows = AltModel::evasion();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] evasion lookup failed: ' . $e->getMessage());
            $error = true;
        }
        $this->view('players/evasion', [
            'rows'      => $rows,
            'available' => AltModel::available(),
            'error'     => $error,
            'avatars'   => Steam::avatars(array_merge(array_column($rows, 'steamid'), array_filter(array_column($rows, 'banned_steamid')))),
        ]);
    }

    /** Players page: search by name / any SteamID format, or recently sanctioned players. */
    public function index(): void
    {
        Auth::requireModerate();
        $q = trim((string) ($this->query('search', '') ?: $this->query('q', '')));
        $found = mb_strlen($q) >= 2 ? self::lookup($q, 30) : self::recent(30);
        $sids = array_map('strval', array_keys($found));
        $this->view('players/index', [
            'q'       => $q,
            'players' => $found,
            'roles'   => self::roles($sids),
            'avatars' => Steam::avatars($sids),
        ]);
    }

    /**
     * Admin group and active VIP group for a batch of players.
     *
     * @param string[] $sids
     * @return array<string,array{admin:?string,vip:?string}>
     */
    private static function roles(array $sids): array
    {
        $out = [];
        if (!$sids) {
            return $out;
        }
        $p = self::p();
        $ph = implode(',', array_fill(0, count($sids), '?'));
        $sql = "SELECT a.player_steamid AS sid, g.name AS gname FROM {$p}admins a
                LEFT JOIN {$p}groups g ON g.id = a.group_id WHERE a.player_steamid IN ({$ph})";
        try {
            $admins = Database::all($sql . ' AND (a.ends IS NULL OR a.ends > NOW())', $sids);
        } catch (\Throwable $e) {
            $admins = self::safeAll($sql, $sids);
        }
        foreach ($admins as $r) {
            $sid = (string) $r['sid'];
            $g = trim((string) ($r['gname'] ?? ''));
            if (!isset($out[$sid]['admin']) || ($out[$sid]['admin'] === 'Admin' && $g !== '')) {
                $out[$sid]['admin'] = $g !== '' ? $g : 'Admin';
            }
        }

        if (VipModel::enabled()) {
            $ids = [];
            foreach ($sids as $sid) {
                $ids[(string) Steam::toAccountId($sid)] = $sid;
            }
            $ignored = VipModel::ignoredGroups();
            $vph = implode(',', array_fill(0, count($ids), '?'));
            $rows = self::safeAll(
                'SELECT account_id, `group` FROM `' . Config::get('vipcore.users_table', 'vip_users') . "`
                 WHERE account_id IN ({$vph}) AND (expires = 0 OR expires > ?) ORDER BY expires = 0 DESC, expires DESC",
                array_merge(array_map('intval', array_keys($ids)), [time()])
            );
            foreach ($rows as $r) {
                $sid = $ids[(string) $r['account_id']] ?? null;
                $g = trim((string) ($r['group'] ?? ''));
                if ($sid !== null && !isset($out[$sid]['vip']) && !in_array($g, $ignored, true)) {
                    $out[$sid]['vip'] = $g !== '' ? $g : 'VIP';
                }
            }
        }
        foreach ($out as &$r) {
            $r += ['admin' => null, 'vip' => null];
        }
        return $out;
    }

    /** JSON search for the command palette (players are staff-only). */
    public function search(): void
    {
        Auth::requireLogin();
        $q = trim((string) $this->query('q', ''));
        if (!Auth::canModerate() || mb_strlen($q) < 2) {
            $this->json(['players' => []]);
        }
        $players = [];
        foreach (self::lookup($q, 8) as $sid => $name) {
            $players[] = [
                'steamid' => (string) $sid,
                'name'    => $name !== '' ? $name : (string) $sid,
                'url'     => Url::to('/player/' . $sid),
            ];
        }
        $this->json(['players' => $players]);
    }

    /**
     * Players matching a name fragment or any SteamID format.
     *
     * @return array<string,string> steamid64 => last known name
     */
    private static function lookup(string $q, int $limit): array
    {
        $p = self::p();
        $steamId = Steam::toSteamId64Any($q);
        $found = [];
        foreach (["{$p}bans", "{$p}mutes", "{$p}warns", "{$p}admins"] as $table) {
            $rows = $steamId !== null
                ? self::safeAll("SELECT player_steamid AS sid, player_name AS name FROM {$table} WHERE player_steamid = ? LIMIT 1", [$steamId])
                : self::safeAll(
                    "SELECT player_steamid AS sid, MAX(player_name) AS name FROM {$table}
                     WHERE player_name LIKE ? AND player_steamid IS NOT NULL GROUP BY player_steamid LIMIT {$limit}",
                    ['%' . addcslashes($q, '%_\\') . '%']
                );
            foreach ($rows as $r) {
                $sid = (string) $r['sid'];
                if ($sid !== '' && !isset($found[$sid])) {
                    $found[$sid] = (string) ($r['name'] ?? '');
                }
            }
            if (count($found) >= $limit) {
                break;
            }
        }
        if ($steamId !== null && !isset($found[$steamId])) {
            $found[$steamId] = '';
        }
        return array_slice($found, 0, $limit, true);
    }

    /**
     * Most recently sanctioned players (bans, mutes, warns), newest first.
     *
     * @return array<string,string> steamid64 => name
     */
    private static function recent(int $limit): array
    {
        $p = self::p();
        $rows = [];
        foreach (["{$p}bans", "{$p}mutes", "{$p}warns"] as $table) {
            $rows = array_merge($rows, self::safeAll(
                "SELECT player_steamid AS sid, player_name AS name, created FROM {$table}
                 WHERE player_steamid IS NOT NULL AND player_steamid <> '' ORDER BY id DESC LIMIT {$limit}"
            ));
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['created'], (string) $a['created']));
        $found = [];
        foreach ($rows as $r) {
            $sid = (string) $r['sid'];
            if (!isset($found[$sid])) {
                $found[$sid] = (string) ($r['name'] ?? '');
            }
        }
        return array_slice($found, 0, $limit, true);
    }
}