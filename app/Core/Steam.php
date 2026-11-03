<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Helpers for Steam identifiers and conversions between the formats
 * used by SimpleAdmin (full SteamID64) and VIPCore (32-bit account_id).
 */
final class Steam
{
    /** Standard Steam offset for individual accounts. */
    public const BASE = 76561197960265728;
    private const MAX_ACCOUNT_ID = 4294967295;

    /** SteamID64 -> account_id (folosit de VIPCore). */
    public static function toAccountId(string|int $steamid64): int
    {
        return (int) ((int) $steamid64 - self::BASE);
    }

    /** account_id (VIPCore) -> SteamID64 complet. */
    public static function toSteamId64(int $accountId): string
    {
        return (string) ($accountId + self::BASE);
    }

    /** Validare simpla SteamID64. */
    public static function isValidSteamId64(string $value): bool
    {
        return ctype_digit($value) && strlen($value) === 17 && (int) $value > self::BASE;
    }

    /**
     * Normalize ANY common Steam identifier to a full SteamID64 string.
     *
     * Accepts:
     *   - SteamID64:  76561197960265728+  (returned as-is)
     *   - SteamID2:   STEAM_0:Y:Z / STEAM_1:Y:Z  (Y = 0|1, Z = account number)
     *   - SteamID3:   [U:1:W] or U:1:W           (W = 32-bit account id)
     *   - AccountID:  a positive unsigned 32-bit account number
     *
     * Formula: SteamID64 = BASE + (Z * 2) + Y   (equivalently BASE + W).
     * Returns null if the input is not a recognizable Steam id.
     */
    public static function toSteamId64Any(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Already a SteamID64.
        if (self::isValidSteamId64($value)) {
            return (int) $value <= self::BASE + self::MAX_ACCOUNT_ID ? $value : null;
        }

        // SteamID2: STEAM_X:Y:Z  (X is the "universe", ignored; Y is 0 or 1).
        if (preg_match('/^STEAM_[0-5]:([01]):(\d+)$/iD', $value, $m)) {
            $y = (int) $m[1];
            $z = self::parseAccountNumber($m[2]);
            if ($z === null) return null;
            $accountId = $z * 2 + $y;
            return $accountId > 0 && $accountId <= self::MAX_ACCOUNT_ID ? self::toSteamId64($accountId) : null;
        }

        // SteamID3: [U:1:W] or U:1:W  (W is the 32-bit account id).
        if (preg_match('/^(?:\[U:1:(\d+)\]|U:1:(\d+))$/iD', $value, $m)) {
            $accountId = self::parseAccountNumber($m[1] !== '' ? $m[1] : $m[2]);
            return $accountId !== null && $accountId > 0 ? self::toSteamId64($accountId) : null;
        }

        // Bare account id (VIPCore-style 32-bit number) — only when clearly not a
        // truncated SteamID64. Kept conservative to avoid mis-parsing.
        if (ctype_digit($value) && strlen($value) <= 10) {
            $id = self::parseAccountNumber($value);
            if ($id !== null && $id > 0) {
                return self::toSteamId64($id);
            }
        }

        return null;
    }

    private static function parseAccountNumber(string $value): ?int
    {
        $number = ltrim($value, '0');
        if ($number === '') return 0;
        if (strlen($number) > 10 || (strlen($number) === 10 && strcmp($number, (string) self::MAX_ACCOUNT_ID) > 0)) {
            return null;
        }
        return (int) $number;
    }

    /** Equivalent strings for plugins that store textual Steam identifiers. */
    public static function identifierVariants(string $value): array
    {
        $steamId64 = self::toSteamId64Any($value);
        if ($steamId64 === null) return [];
        $accountId = self::toAccountId($steamId64);
        $suffix = ($accountId % 2) . ':' . intdiv($accountId, 2);
        return [
            $steamId64,
            (string) $accountId,
            'STEAM_0:' . $suffix,
            'STEAM_1:' . $suffix,
            '[U:1:' . $accountId . ']',
            'U:1:' . $accountId,
        ];
    }

    /** Link catre profilul Steam. */
    public static function profileUrl(string|int $steamid64): string
    {
        return 'https://steamcommunity.com/profiles/' . $steamid64;
    }

    /** Staff open the panel player profile; everyone else goes to Steam. */
    public static function playerUrl(string|int $steamid64): string
    {
        return Auth::canModerate() ? Url::to('/player/' . $steamid64) : self::profileUrl($steamid64);
    }

    /** href + target attributes for a player link (data-steamid enables the right-click menu). */
    public static function linkAttrs(string|int $steamid64): string
    {
        $sid = View::e((string) $steamid64);
        $attrs = 'href="' . View::e(self::playerUrl($steamid64)) . '" data-steamid="' . $sid . '"';
        return Auth::canModerate() ? $attrs : $attrs . ' target="_blank" rel="noopener"';
    }

    /**
     * The Steam Web API key. A value set in Settings (UI) takes precedence over
     * the one in config.php, so root can change it without editing files.
     */
    public static function apiKey(): string
    {
        $fromSettings = trim((string) Settings::get('steam_api_key', ''));
        if ($fromSettings !== '') {
            return $fromSettings;
        }
        return (string) Config::get('steam.api_key', '');
    }

    /** Placeholder avatar (used until the real one loads from the API). */
    public static function avatarPlaceholder(): string
    {
        return 'https://avatars.cloudflare.steamstatic.com/fef49e7fa7e1997310d705b2a6158ff8dc1cdfeb_full.jpg';
    }

    /**
     * A player cell: avatar + name linking to the Steam profile. Falls back to
     * the placeholder avatar when none is known, and to plain text when the id
     * isn't a usable SteamID64.
     *
     * @param array<string,string> $avatars steamid64 => avatar URL
     */
    public static function playerCell(string $steamId64, string $name, array $avatars = []): string
    {
        $label = View::e($name !== '' ? $name : $steamId64);
        if (!self::isValidSteamId64($steamId64)) {
            return '<span class="pname">' . $label . '</span>';
        }
        $avatar = View::e($avatars[$steamId64] ?? self::avatarPlaceholder());
        return '<a class="player-cell" ' . self::linkAttrs($steamId64) . '>'
            . '<img class="player-avatar" src="' . $avatar . '" alt="" loading="lazy">'
            . '<span class="pname">' . $label . '</span></a>';
    }

    /** The SteamID64 rendered as a link to the profile (plain text if invalid). */
    public static function idLink(string $steamId64): string
    {
        if (!self::isValidSteamId64($steamId64)) {
            return View::e($steamId64);
        }
        return '<a ' . self::linkAttrs($steamId64) . '>'
            . View::e($steamId64) . '</a>';
    }
    
    /** A name linked to the Steam profile, no avatar (plain text if invalid/not a player, e.g. "Console"). */
    public static function nameLink(string $steamId64, string $name): string
    {
        $label = View::e($name !== '' ? $name : $steamId64);
        if (!self::isValidSteamId64($steamId64)) {
            return '<span class="pname">' . $label . '</span>';
        }
        return '<a ' . self::linkAttrs($steamId64) . '>' . $label . '</a>';
    }

    /**
     * Batch-resolve Steam avatars for a list of SteamID64s using the Steam Web
     * API (GetPlayerSummaries, up to 100 ids per call). Returns a map
     * steamid64 => avatar URL. Missing ids / no API key -> placeholder.
     * Results are memoized per request so repeated calls are cheap.
     *
     * @param string[] $steamIds
     * @return array<string, string>
     */
    public static function avatars(array $steamIds): array
    {
        // Per-request memo of resolved avatar URLs.
        static $cache = [];
        // Persistent disk cache (loaded once per request), so navigating between
        // list pages does NOT hit the Steam Web API every time. Entries expire
        // after AVATAR_TTL seconds; only cache misses trigger a network call.
        static $diskInit = false;

        if (!$diskInit) {
            $diskInit = true;
            self::avatarDiskLoad();
            // Flush any newly fetched entries at the end of the request.
            if (!self::$shutdownRegistered) {
                self::$shutdownRegistered = true;
                register_shutdown_function(static function (): void {
                    self::avatarDiskFlush();
                });
            }
        }

        $now = time();

        // Seed the per-request memo from the disk cache (fresh entries only).
        foreach ($steamIds as $id) {
            $id = (string) $id;
            if ($id === '' || isset($cache[$id])) {
                continue;
            }
            $hit = self::$avatarCache[$id] ?? null;
            if ($hit && ($now - (int) ($hit['ts'] ?? 0)) < self::AVATAR_TTL) {
                $cache[$id] = (string) $hit['url'];
            }
        }

        // Normalize + keep only valid, not-yet-resolved ids to fetch.
        $ids = [];
        foreach ($steamIds as $id) {
            $id = (string) $id;
            if ($id !== '' && !isset($cache[$id])) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);

        $key = self::apiKey();
        if ($key === '' || !$ids || !function_exists('curl_init')) {
            // No API key or nothing to fetch: fill the requested ones with placeholder.
            foreach ($steamIds as $id) {
                $id = (string) $id;
                if ($id !== '' && !isset($cache[$id])) {
                    $cache[$id] = self::avatarPlaceholder();
                }
            }
            return self::pick($cache, $steamIds);
        }

        // Steam allows up to 100 ids per request.
        foreach (array_chunk($ids, 100) as $chunk) {
            $url = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key='
                . urlencode($key) . '&steamids=' . urlencode(implode(',', $chunk));
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            $json = curl_exec($ch);
            curl_close($ch);

            foreach (self::storeSummaries($json) as $sid => $avatar) {
                $cache[$sid] = $avatar;
            }
            // Any id the API didn't return -> placeholder for this request only
            // (do NOT persist, so a temporary miss is retried next time).
            foreach ($chunk as $id) {
                if (!isset($cache[$id])) {
                    $cache[$id] = self::avatarPlaceholder();
                }
            }
        }

        return self::pick($cache, $steamIds);
    }

    /**
     * Persist avatar + persona name from a GetPlayerSummaries response.
     * @return array<string,string> steamid => avatar url
     */
    private static function storeSummaries(string|false $json): array
    {
        $out = [];
        $players = $json !== false ? (json_decode((string) $json, true)['response']['players'] ?? []) : [];
        foreach ((array) $players as $p) {
            if (empty($p['steamid'])) {
                continue;
            }
            $sid = (string) $p['steamid'];
            $out[$sid] = (string) ($p['avatarfull'] ?? $p['avatarmedium'] ?? self::avatarPlaceholder());
            self::$avatarCache[$sid] = ['url' => $out[$sid], 'name' => (string) ($p['personaname'] ?? ''), 'ts' => time()];
            self::$avatarDirty = true;
        }
        return $out;
    }

    /** Current Steam persona name ('' when unknown or no API key). */
    public static function personaName(string $steamId64): string
    {
        self::avatars([$steamId64]);
        $hit = self::$avatarCache[$steamId64] ?? null;
        if (is_array($hit) && isset($hit['name']) && (time() - (int) ($hit['ts'] ?? 0)) < self::AVATAR_TTL) {
            return (string) $hit['name'];
        }
        $key = self::apiKey();
        if ($key === '' || !function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key=' . urlencode($key) . '&steamids=' . urlencode($steamId64));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 1]);
        $json = curl_exec($ch);
        curl_close($ch);
        self::storeSummaries($json);
        return (string) (self::$avatarCache[$steamId64]['name'] ?? '');
    }

    /** TTL for a cached avatar URL (12 hours). */
    private const AVATAR_TTL = 43200;

    private static ?array $avatarCache = null;
    private static bool $avatarDirty = false;
    private static bool $shutdownRegistered = false;

    private static function avatarCacheFile(): string
    {
        return BASE_PATH . '/.avatar_cache.json';
    }

    /** @return array<string,array{url:string,ts:int}> */
    private static function avatarDiskLoad(): array
    {
        if (self::$avatarCache !== null) {
            return self::$avatarCache;
        }
        $f = self::avatarCacheFile();
        $out = [];
        if (is_file($f)) {
            $data = json_decode((string) @file_get_contents($f), true);
            if (is_array($data)) {
                $out = $data;
            }
        }
        return self::$avatarCache = $out;
    }

    /** Write the avatar cache back to disk (called on shutdown when changed). */
    private static function avatarDiskFlush(): void
    {
        if (!self::$avatarDirty || self::$avatarCache === null) {
            return;
        }
        $data = self::$avatarCache;
        // Bound the cache size so it can't grow without limit; keep newest.
        if (count($data) > 5000) {
            uasort($data, static fn($a, $b) => (int) ($b['ts'] ?? 0) <=> (int) ($a['ts'] ?? 0));
            $data = array_slice($data, 0, 5000, true);
        }
        @file_put_contents(self::avatarCacheFile(), json_encode($data), LOCK_EX);
        self::$avatarDirty = false;
    }

    /** @param array<string,string> $cache @param string[] $ids @return array<string,string> */
    private static function pick(array $cache, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id !== '') {
                $out[$id] = $cache[$id] ?? self::avatarPlaceholder();
            }
        }
        return $out;
    }
}
