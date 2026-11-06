<?php

declare(strict_types=1);

namespace App\Core;

/**
 * WeaponPaints catalog + per-item localisation.
 *
 * Native port of the standalone Skins module's UtilsClass + CategoriesClass +
 * DataI18n (reference: d:/CS2/skins/class/{utils,categories,data_i18n}.php).
 * The source's UI/Tailwind helpers (teamBadgeClasses, sidebarBadge, logo/
 * favicon/isAdmin, …) are intentionally dropped — the panel view supplies its
 * own markup. The numeric team helpers, label helpers and all JSON loaders are
 * preserved verbatim in behaviour.
 *
 * JSON item data lives at BASE_PATH . '/app/Data/weaponpaints/' and is loaded
 * for the panel's current language (Lang::current(): en/ro/ru/de), always with
 * the English list as the base so partially-translated packs never drop items.
 */
final class WeaponPaintsData
{
    public const TEAM_BOTH = 1;
    public const TEAM_T = 2;
    public const TEAM_CT = 3;

    public const KNIFE_DEFINDEXES = [
        500, 503, 505, 506, 507, 508, 509, 512, 514, 515, 516,
        517, 518, 519, 520, 521, 522, 523, 525, 526,
    ];

    public const GLOVE_DEFINDEXES = [4725, 5027, 5030, 5031, 5032, 5033, 5034, 5035];

    private const TEAM_EXCLUSIVE_WEAPONS = [
        7 => self::TEAM_T, 11 => self::TEAM_T, 13 => self::TEAM_T,
        17 => self::TEAM_T, 29 => self::TEAM_T, 30 => self::TEAM_T,
        39 => self::TEAM_T, 4 => self::TEAM_T,
        3 => self::TEAM_CT, 8 => self::TEAM_CT, 10 => self::TEAM_CT,
        16 => self::TEAM_CT, 27 => self::TEAM_CT, 32 => self::TEAM_CT,
        33 => self::TEAM_CT, 34 => self::TEAM_CT, 38 => self::TEAM_CT,
        60 => self::TEAM_CT, 61 => self::TEAM_CT,
    ];

    /** Per-request cache of merged i18n item lists, keyed by "<category>:<lang>". */
    private static array $i18nCache = [];

    /** Per-request cache of the paint_index -> rarity color map. */
    private static ?array $rarityColors = null;

    /** Per-request cache of the agent model -> rarity color map. */
    private static ?array $agentColors = null;

    /** Per-request cache of per-category item id -> color maps. */
    private static ?array $itemColors = null;

    public static function getForcedTeam(int $defindex): ?int
    {
        return self::TEAM_EXCLUSIVE_WEAPONS[$defindex] ?? null;
    }

    /**
     * Map of paint index -> rarity info { color, rarity }, loaded from
     * rarity_colors.json (generated from ByMykel/CSGO-API — the colours live
     * in the data, nothing is hardcoded). Empty when the file is absent.
     *
     * @return array<int, array{color:string, rarity:string}>
     */
    public static function rarityColors(): array
    {
        if (self::$rarityColors !== null) {
            return self::$rarityColors;
        }
        $path = BASE_PATH . '/app/Data/weaponpaints/rarity_colors.json';
        $data = self::readJson($path);
        $out = [];
        foreach ($data as $paintIndex => $info) {
            if (!is_array($info)) {
                continue;
            }
            $color = (string) ($info['color'] ?? '');
            if ($color === '') {
                continue;
            }
            $out[(int) $paintIndex] = [
                'color'  => $color,
                'rarity' => (string) ($info['rarity'] ?? ''),
            ];
        }
        return self::$rarityColors = $out;
    }

    /** Absolute path to the weaponpaints data directory. */
    private static function dataDir(): string
    {
        return BASE_PATH . '/app/Data/weaponpaints';
    }

    /** Absolute path to the generated rarity colour map. */
    private static function rarityColorsPath(): string
    {
        return self::dataDir() . '/rarity_colors.json';
    }

    /** Absolute path to the generated agent colour map. */
    private static function agentColorsPath(): string
    {
        return self::dataDir() . '/agent_colors.json';
    }

    /**
     * Normalise an agent model path to the key both data sets agree on, e.g.
     * "agents/models/tm_professional/tm_professional_varf5.vmdl" (ByMykel) and
     * "tm_professional/tm_professional_varf5" (Nereziel) both become
     * "tm_professional/tm_professional_varf5".
     */
    private static function agentModelKey(string $model): string
    {
        $m = trim($model);
        $m = preg_replace('#^agents/models/#', '', $m) ?? $m;
        $m = preg_replace('#\.vmdl$#', '', $m) ?? $m;
        return strtolower($m);
    }

    /**
     * Regenerate agent_colors.json (model -> {color, rarity}) from ByMykel's
     * agents.json. Same atomic-write + sanity-floor pattern as the paint map.
     *
     * @return array{ok:bool, count:int, error:string}
     */
    public static function refreshAgentColors(): array
    {
        $sources = [
            'https://raw.githubusercontent.com/ByMykel/CSGO-API/main/public/api/en/agents.json',
            'https://cdn.jsdelivr.net/gh/ByMykel/CSGO-API@main/public/api/en/agents.json',
        ];
        $raw = '';
        $lastErr = '';
        foreach ($sources as $url) {
            [$body, $err] = self::httpGet($url);
            if ($body !== '') {
                $raw = $body;
                break;
            }
            $lastErr = $err;
        }
        if ($raw === '') {
            return ['ok' => false, 'count' => 0, 'error' => $lastErr !== '' ? $lastErr : 'download failed'];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'count' => 0, 'error' => 'invalid JSON'];
        }

        $map = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $model = (string) ($item['model_player'] ?? '');
            $color = $item['rarity']['color'] ?? null;
            if ($model === '' || !is_string($color) || $color === '') {
                continue;
            }
            $key = self::agentModelKey($model);
            if ($key !== '' && !isset($map[$key])) {
                $map[$key] = ['color' => $color, 'rarity' => (string) ($item['rarity']['name'] ?? '')];
            }
        }
        if (count($map) < 20) {
            return ['ok' => false, 'count' => count($map), 'error' => 'too few agents parsed'];
        }

        ksort($map);
        $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'count' => 0, 'error' => 'encode failed'];
        }
        $out = self::agentColorsPath();
        $tmp = $out . '.tmp';
        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $out)) {
            @unlink($tmp);
            return ['ok' => false, 'count' => 0, 'error' => 'could not write file'];
        }
        self::$agentColors = null;
        return ['ok' => true, 'count' => count($map), 'error' => ''];
    }

    /** @return array<string, array{color:string, rarity:string}> */
    public static function agentColors(): array
    {
        if (self::$agentColors !== null) {
            return self::$agentColors;
        }
        $data = self::readJson(self::agentColorsPath());
        $out = [];
        foreach ($data as $model => $info) {
            if (is_array($info) && !empty($info['color'])) {
                $out[(string) $model] = ['color' => (string) $info['color'], 'rarity' => (string) ($info['rarity'] ?? '')];
            }
        }
        return self::$agentColors = $out;
    }

    /** Rarity colour for an agent model, '' when unknown. From data, not code. */
    public static function agentColor(string $model): string
    {
        $model = trim($model);
        if ($model === '' || $model === 'null') {
            return '';
        }
        return self::agentColors()[self::agentModelKey($model)]['color'] ?? '';
    }

    /** Absolute path to the generated per-category item colour map. */
    private static function itemColorsPath(): string
    {
        return self::dataDir() . '/item_colors.json';
    }

    /**
     * Regenerate item_colors.json: a per-category map of def_index -> color for
     * stickers, keychains, music kits and collectibles (pins/coins), from
     * ByMykel/CSGO-API. Keyed by def_index because the panel's own data uses
     * that same numeric id. Atomic write; partial failures keep the old file.
     *
     * @return array{ok:bool, count:int, error:string}
     */
    public static function refreshItemColors(): array
    {
        // category => ByMykel endpoint
        $endpoints = [
            'stickers'     => 'stickers.json',
            'keychains'    => 'keychains.json',
            'music'        => 'music_kits.json',
            'collectibles' => 'collectibles.json',
        ];
        $out = [];
        $total = 0;
        foreach ($endpoints as $cat => $file) {
            $body = '';
            foreach ([
                'https://raw.githubusercontent.com/ByMykel/CSGO-API/main/public/api/en/' . $file,
                'https://cdn.jsdelivr.net/gh/ByMykel/CSGO-API@main/public/api/en/' . $file,
            ] as $url) {
                [$b] = self::httpGet($url);
                if ($b !== '') { $body = $b; break; }
            }
            if ($body === '') {
                continue; // skip this category; others still refresh
            }
            $data = json_decode($body, true);
            // Free the raw body string right away; on low-memory hosts holding
            // both the ~MB string AND the decoded array at once is what OOMs.
            unset($body);
            if (!is_array($data)) {
                unset($data);
                continue;
            }
            $sub = [];
            foreach ($data as $it) {
                if (!is_array($it)) {
                    continue;
                }
                // def_index is the numeric id the panel's data keys on. Some
                // entries (e.g. music) may only carry it as part of the id.
                $id = $it['def_index'] ?? null;
                if ($id === null && isset($it['id']) && preg_match('/(\d+)$/', (string) $it['id'], $mm)) {
                    $id = $mm[1];
                }
                $color = $it['rarity']['color'] ?? null;
                if ($id === null || !is_string($color) || $color === '') {
                    continue;
                }
                $sub[(string) (int) $id] = $color;
            }
            if ($sub) {
                $out[$cat] = $sub;
                $total += count($sub);
            }
            // Release the big decoded array before fetching the next file so
            // peak memory stays near one file, not the sum of all of them.
            unset($data, $sub);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        if ($total < 50) {
            return ['ok' => false, 'count' => $total, 'error' => 'too few item colours parsed'];
        }

        $json = json_encode($out, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'count' => 0, 'error' => 'encode failed'];
        }
        $path = self::itemColorsPath();
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'count' => 0, 'error' => 'could not write file'];
        }
        self::$itemColors = null;
        return ['ok' => true, 'count' => $total, 'error' => ''];
    }

    /** @return array<string, array<string,string>> category -> id -> color */
    public static function itemColors(): array
    {
        if (self::$itemColors !== null) {
            return self::$itemColors;
        }
        $data = self::readJson(self::itemColorsPath());
        $out = [];
        foreach (['stickers', 'keychains', 'music', 'collectibles'] as $cat) {
            $out[$cat] = is_array($data[$cat] ?? null) ? $data[$cat] : [];
        }
        return self::$itemColors = $out;
    }

    /** Rarity colour for a category item by id, '' when unknown. */
    public static function itemColor(string $category, int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        return self::itemColors()[$category][(string) $id] ?? '';
    }

    /**
     * Update EVERYTHING in one go, for the Settings "Check for updates" button:
     *   1. re-download every item JSON (skins/gloves/agents/stickers/keychains/
     *      music/collectibles, all languages) from Nereziel/cs2-WeaponPaints —
     *      the same source + exact format the panel already uses, so files are
     *      overwritten 1:1 with no conversion;
     *   2. regenerate rarity_colors.json from ByMykel/CSGO-API.
     *
     * Only files that already exist locally are refreshed (we don't invent new
     * languages). Each file is written atomically and only when its download is
     * valid JSON, so a partial/failed run never corrupts the catalog.
     *
     * @return array{ok:bool, updated:int, failed:int, rarity:int, error:string}
     */
    public static function refreshAll(): array
    {
        $dir = self::dataDir();
        if (!is_dir($dir)) {
            return ['ok' => false, 'updated' => 0, 'failed' => 0, 'rarity' => 0, 'error' => 'data directory missing'];
        }

        $base = 'https://raw.githubusercontent.com/Nereziel/cs2-WeaponPaints/main/website/data/';
        $categories = ['skins', 'gloves', 'agents', 'stickers', 'keychains', 'music', 'collectibles'];

        $updated = 0;
        $failed  = 0;
        $lastErr = '';
        $jsdelivr = 'https://cdn.jsdelivr.net/gh/Nereziel/cs2-WeaponPaints@main/website/data/';

        // Collect every per-language file we already have across all categories.
        // path => filename.
        $files = [];
        foreach ($categories as $cat) {
            foreach (glob($dir . '/' . $cat . '_*.json') ?: [] as $path) {
                $files[$path] = basename($path);
            }
        }

        // Download in SMALL parallel batches, writing each file straight to disk
        // and freeing its body before the next batch. Accumulating all ~200
        // bodies in one array OOMs on low-memory hosts (seen at ~60MB cap), so
        // we never hold more than one batch of bodies at a time.
        $paths = array_keys($files);
        foreach (array_chunk($paths, 6) as $batch) {
            $urls = [];
            foreach ($batch as $path) {
                $urls[$path] = $base . rawurlencode($files[$path]);
            }
            $bodies = self::httpGetMany($urls);

            // jsDelivr fallback for any that failed in this batch.
            $retry = [];
            foreach ($batch as $path) {
                if (($bodies[$path] ?? '') === '') {
                    $retry[$path] = $jsdelivr . rawurlencode($files[$path]);
                }
            }
            if ($retry) {
                foreach (self::httpGetMany($retry) as $path => $body) {
                    if ($body !== '') { $bodies[$path] = $body; }
                }
            }

            foreach ($batch as $path) {
                $file = $files[$path];
                $body = $bodies[$path] ?? '';
                if ($body === '') {
                    $failed++;
                    $lastErr = 'download failed for ' . $file;
                    continue;
                }
                                                                                
                // Reject error pages without decoding the full catalog twice.
                if ($body[0] !== '[' && $body[0] !== '{') {
                    $failed++;
                    $lastErr = 'invalid JSON for ' . $file;
                    continue;
                }
                $tmp = $path . '.tmp';
                if (@file_put_contents($tmp, $body) === false || !@rename($tmp, $path)) {
                    @unlink($tmp);
                    $failed++;
                    $lastErr = 'could not write ' . $file;
                    continue;
                }
                $updated++;
            }
            unset($bodies, $urls, $retry);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        // Then refresh the rarity colour map (separate source: ByMykel).
        $rarity = self::refreshRarityColors();

        // Agent rarity colours (separate ByMykel file, keyed by model).
        self::refreshAgentColors();

        // Sticker / keychain / music / collectible rarity colours (ByMykel,
        // keyed by def_index). One combined file with per-category sub-maps.
        self::refreshItemColors();

                                                                            
        self::$i18nCache = [];
        self::$agentColors = null;
        self::$itemColors = null;

        $ok = $updated > 0 && $failed === 0 && !empty($rarity['ok']);
        $error = '';
        if ($failed > 0) {
            $error = $failed . ' file(s) failed' . ($lastErr !== '' ? ': ' . $lastErr : '');
        } elseif (empty($rarity['ok'])) {
            $error = 'rarity colours: ' . ($rarity['error'] ?? 'failed');
        }

        return [
            'ok'      => $ok,
            'updated' => $updated,
            'failed'  => $failed,
            'rarity'  => (int) ($rarity['count'] ?? 0),
            'error'   => $error,
        ];
    }

    /**
     * Download the latest skin catalog from ByMykel/CSGO-API and regenerate
     * rarity_colors.json (paint_index -> {color, rarity}). The colours live in
     * the data, not in code — this just refreshes the local copy so new skins
     * get their in-game rarity colour. Atomic write (temp + rename); on any
     * failure the existing file is left untouched.
     *
     * @return array{ok:bool, count:int, error:string}
     */
    public static function refreshRarityColors(): array
    {
        $sources = [
            'https://raw.githubusercontent.com/ByMykel/CSGO-API/main/public/api/en/skins.json',
            'https://cdn.jsdelivr.net/gh/ByMykel/CSGO-API@main/public/api/en/skins.json',
        ];
        $raw = '';
        $lastErr = '';
        foreach ($sources as $url) {
            [$body, $err] = self::httpGet($url);
            if ($body !== '') {
                $raw = $body;
                break;
            }
            $lastErr = $err;
        }
        if ($raw === '') {
            return ['ok' => false, 'count' => 0, 'error' => $lastErr !== '' ? $lastErr : 'download failed'];
        }
        return self::buildRarityColorsFrom($raw);
    }

    /**
     * Same as refreshRarityColors() but from an already-downloaded skins.json
     * file (for hosts whose PHP CLI has no outbound network).
     *
     * @return array{ok:bool, count:int, error:string}
     */
    public static function refreshRarityColorsFromFile(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'count' => 0, 'error' => 'file not found'];
        }
        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return ['ok' => false, 'count' => 0, 'error' => 'file is empty'];
        }
        return self::buildRarityColorsFrom($raw);
    }

    /**
     * Parse a ByMykel skins.json body into the paint_index -> {color, rarity}
     * map and write it atomically. Shared by the URL + file refresh paths.
     *
     * @return array{ok:bool, count:int, error:string}
     */
    private static function buildRarityColorsFrom(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'count' => 0, 'error' => 'invalid JSON from source'];
        }

        $map = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $paint = $item['paint_index'] ?? null;
            $color = $item['rarity']['color'] ?? null;
            if ($paint === null || !is_string($color) || $color === '') {
                continue;
            }
            $key = (string) (int) $paint;
            if (!isset($map[$key])) {
                $map[$key] = ['color' => $color, 'rarity' => (string) ($item['rarity']['name'] ?? '')];
            }
        }

        // Sanity floor: the real catalog has 1400+ paints. A tiny result means a
        // bad download; keep the existing file rather than wiping it.
        if (count($map) < 500) {
            return ['ok' => false, 'count' => count($map), 'error' => 'too few entries parsed (' . count($map) . ')'];
        }

        ksort($map, SORT_NUMERIC);
        $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'count' => 0, 'error' => 'could not encode JSON'];
        }

        $out = self::rarityColorsPath();
        $dir = dirname($out);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'count' => 0, 'error' => 'output dir not writable'];
        }
        $tmp = $out . '.tmp';
        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $out)) {
            @unlink($tmp);
            return ['ok' => false, 'count' => 0, 'error' => 'could not write file'];
        }

        self::$rarityColors = null; // drop the per-request cache
        return ['ok' => true, 'count' => count($map), 'error' => ''];
    }

    /**
     * Minimal HTTP GET (cURL first, stream wrapper fallback).
     * @return array{0:string,1:string} [body, error]
     */
    private static function httpGet(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => 'cs2-panel-rarity/1.0',
            ]);
            $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
            if ($ca && is_file($ca)) {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
            }
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if (is_string($body) && $body !== '' && $code >= 200 && $code < 300) {
                return [$body, ''];
            }
            return ['', "http {$code}" . ($err !== '' ? " {$err}" : '')];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: cs2-panel-rarity/1.0\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        return [is_string($body) ? $body : '', is_string($body) ? '' : 'allow_url_fopen fetch failed'];
    }

    /**
     * Fetch many URLs in parallel with curl_multi (falls back to sequential
     * httpGet when the multi API isn't available). This is what keeps a full
     * refresh of ~200 per-language files inside the web server's gateway
     * timeout — sequential downloads would run for minutes and get killed.
     *
     * @param array<string,string> $urls  key => url
     * @return array<string,string> key => body ('' when that fetch failed)
     */
    private static function httpGetMany(array $urls): array
    {
        $out = [];
        foreach ($urls as $k => $_) {
            $out[$k] = '';
        }
        if (!function_exists('curl_multi_init') || !function_exists('curl_init')) {
            // No multi support: fall back to sequential (slower, but correct).
            foreach ($urls as $k => $url) {
                [$body] = self::httpGet($url);
                $out[$k] = $body;
            }
            return $out;
        }

        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        // Cap concurrency so we don't open many sockets at once (some hosts
        // throttle that) and don't hold too many response bodies in memory on
        // low-memory hosts; small batches keep the footprint tiny.
        foreach (array_chunk($urls, 6, true) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $k => $url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 25,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_USERAGENT      => 'cs2-panel-rarity/1.0',
                ]);
                if ($ca && is_file($ca)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $ca);
                }
                curl_multi_add_handle($mh, $ch);
                $handles[$k] = $ch;
            }
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $k => $ch) {
                $body = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (is_string($body) && $body !== '' && $code >= 200 && $code < 300) {
                    $out[$k] = $body;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
        return $out;
    }

    /**
     * The rarity hex colour for a paint index, or '' when unknown. Nothing is
     * hardcoded — the value comes straight from rarity_colors.json.
     */
    public static function paintColor(int $paint): string
    {
        if ($paint <= 0) {
            return '';
        }
        return self::rarityColors()[$paint]['color'] ?? '';
    }

    /** The rarity name for a paint index, or '' when unknown. */
    public static function paintRarity(int $paint): string
    {
        if ($paint <= 0) {
            return '';
        }
        return self::rarityColors()[$paint]['rarity'] ?? '';
    }

    /** @return array<int,int> map of forced-team weapon defindex => team id */
    public static function forcedTeamWeapons(): array
    {
        return self::TEAM_EXCLUSIVE_WEAPONS;
    }

                                                                        
    // Category map (from CategoriesClass)
                                                                        

    /** @return array<string, int[]> category name => list of weapon_defindex */
    public static function getCategoryMap(): array
    {
        return [
            'Rifles' => [7, 8, 9, 10, 11, 13, 16, 38, 39, 40, 60],
            'SMGs' => [17, 19, 23, 24, 26, 33, 34],
            'Pistols' => [1, 2, 3, 4, 30, 32, 36, 61, 63, 64],
            'Heavy' => [14, 25, 27, 28, 29, 35],
        ];
    }

    public static function getCategoryForDefindex(int $defindex): ?string
    {
        foreach (self::getCategoryMap() as $category => $defindexes) {
            if (in_array($defindex, $defindexes, true)) {
                return $category;
            }
        }
        return null;
    }

    /**
     * @param array $weapons defindex => weapon data (from getWeaponsFromArray)
     * @return array<string, array> category name => [defindex => weapon data]
     */
    public static function groupWeaponsByCategory(array $weapons): array
    {
        $grouped = [];
        foreach (self::getCategoryMap() as $category => $defindexes) {
            $grouped[$category] = [];
        }
        foreach ($weapons as $defindex => $weapon) {
            $category = self::getCategoryForDefindex((int) $defindex);
            if ($category === null) {
                continue; // knives are handled separately
            }
            $grouped[$category][$defindex] = $weapon;
        }
        return array_filter($grouped);
    }

                                                                        
    // JSON item loaders (from UtilsClass)
                                                                        

    public static function skinsFromJson(): array
    {
        $skins = [];
        $json = self::load('skins', 'paint_name', ['weapon_defindex', 'paint']);
        foreach ($json as $skin) {
            $paint = (int) $skin['paint'];
            $skins[(int) $skin['weapon_defindex']][$paint] = [
                'weapon_name' => $skin['weapon_name'],
                'paint_name' => $skin['paint_name'],
                'image_url' => $skin['image'],
                'legacy' => !empty($skin['legacy_model']),
                // Rarity colour straight from rarity_colors.json (not hardcoded).
                'color' => self::paintColor($paint),
            ];
        }
        return $skins;
    }

    public static function glovesFromJson(): array
    {
        $gloves = [];
        $json = self::load('gloves', 'paint_name', ['weapon_defindex', 'paint']);
        foreach ($json as $glove) {
            $defindex = (int) $glove['weapon_defindex'];
            if ($defindex === 0) {
                continue;
            }
            $paint = (int) $glove['paint'];
            $gloves[$defindex][$paint] = [
                'weapon_name' => $glove['weapon_name'] ?? ('glove_' . $defindex),
                'paint_name' => $glove['paint_name'],
                'image_url' => $glove['image'],
                'color' => self::paintColor($paint),
            ];
        }
        return $gloves;
    }

    /** @return array<int, array{team:int, image:string, model:string, agent_name:string, color:string}> */
    public static function agentsFromJson(): array
    {
        $agents = self::load('agents', 'agent_name', ['model', 'team']);
        foreach ($agents as &$a) {
            $a['color'] = self::agentColor((string) ($a['model'] ?? ''));
        }
        unset($a);
        return $agents;
    }

    /** Attach the rarity colour (by id) to a flat item list for a category. */
    private static function withItemColors(array $list, string $category): array
    {
        foreach ($list as &$row) {
            $row['color'] = self::itemColor($category, (int) ($row['id'] ?? 0));
        }
        unset($row);
        return $list;
    }

    /** @return array<int, array{id:string, name:string, image:string, color:string}> */
    public static function stickersFromJson(): array
    {
        return self::withItemColors(self::load('stickers', 'name', ['id']), 'stickers');
    }

    /** @return array<int, array{id:string, name:string, image:string, color:string}> */
    public static function keychainsFromJson(): array
    {
        return self::withItemColors(self::load('keychains', 'name', ['id']), 'keychains');
    }

    /** @return array<int, array{id:string, name:string, image:string, color:string}> */
    public static function musicFromJson(): array
    {
        return self::withItemColors(self::load('music', 'name', ['id']), 'music');
    }

    /** @return array<int, array{id:string, name:string, image:string, color:string}> */
    public static function collectiblesFromJson(): array
    {
        return self::withItemColors(self::load('collectibles', 'name', ['id']), 'collectibles');
    }

                                                                        
    // Derived catalog helpers (from UtilsClass)
                                                                        

    public static function getWeaponsFromArray(): array
    {
        $weapons = [];
        $temp = self::skinsFromJson();
        foreach ($temp as $key => $value) {
            if (key_exists($key, $weapons) || in_array($key, self::KNIFE_DEFINDEXES, true)) {
                continue;
            }
            $weapons[$key] = [
                'weapon_name' => $value[0]['weapon_name'],
                'paint_name' => $value[0]['paint_name'],
                'image_url' => $value[0]['image_url'],
                'display_name' => trim(explode('|', $value[0]['paint_name'])[0]),
            ];
        }
        return $weapons;
    }

    public static function getKnifeTypes(): array
    {
        $knifes = [];
        $temp = self::skinsFromJson();
        foreach (self::KNIFE_DEFINDEXES as $defindex) {
            if (!isset($temp[$defindex][0])) {
                continue;
            }
            $weapon = $temp[$defindex][0];
            $knifes[$defindex] = [
                'weapon_name' => $weapon['weapon_name'],
                'paint_name' => rtrim(explode('|', $weapon['paint_name'])[0]),
                'image_url' => $weapon['image_url'],
            ];
        }
        $knifes[0] = [
            'weapon_name' => 'weapon_knife',
            'paint_name' => 'Default knife',
            'image_url' => 'https://cdn.jsdelivr.net/gh/Nereziel/cs2-WeaponPaints@main/website/img/skins/weapon_knife.png',
        ];
        ksort($knifes);
        return $knifes;
    }

    public static function getGloveTypes(): array
    {
        $gloves = [];
        $temp = self::glovesFromJson();
        foreach (self::GLOVE_DEFINDEXES as $defindex) {
            if (!isset($temp[$defindex])) {
                continue;
            }
            $firstPaintId = array_key_first($temp[$defindex]);
            $glove = $temp[$defindex][$firstPaintId];
            $gloves[$defindex] = [
                'weapon_name' => $glove['weapon_name'],
                'paint_name' => rtrim(explode('|', $glove['paint_name'])[0]),
                'image_url' => $glove['image_url'],
            ];
        }
        $gloves[0] = [
            'weapon_name' => 'default_gloves',
            'paint_name' => 'Default gloves',
            'image_url' => '',
        ];
        ksort($gloves);
        return $gloves;
    }

    public static function getSelectedSkins(array $rows): array
    {
        $selected = [];
        foreach ($rows as $row) {
            $team = isset($row['weapon_team']) ? (int) $row['weapon_team'] : self::TEAM_BOTH;
            // Multi-team support: store under $defindex then $team.
            // Stickers (5 slots) + keychain are optional columns; parse each
            // into {id,x,y} (0 = empty) when present.
            $stickers = [];
            for ($si = 0; $si < 5; $si++) {
                $stickers[$si] = isset($row['weapon_sticker_' . $si])
                    ? \App\Models\WeaponPaintsModel::parseSlot($row['weapon_sticker_' . $si], false)
                    : ['id' => 0, 'x' => 0.0, 'y' => 0.0];
            }
            $keychain = isset($row['weapon_keychain'])
                ? \App\Models\WeaponPaintsModel::parseSlot($row['weapon_keychain'], true)
                : ['id' => 0, 'x' => 0.0, 'y' => 0.0];

            $selected[(int) $row['weapon_defindex']][$team] = [
                'weapon_paint_id' => (int) $row['weapon_paint_id'],
                'weapon_wear' => (float) $row['weapon_wear'],
                'weapon_seed' => (int) $row['weapon_seed'],
                'weapon_team' => $team,
                'weapon_stattrak' => isset($row['weapon_stattrak']) ? (int) $row['weapon_stattrak'] : 0,
                'weapon_stattrak_count' => isset($row['weapon_stattrak_count']) ? (int) $row['weapon_stattrak_count'] : 0,
                'weapon_nametag' => !empty($row['weapon_nametag']) ? (string) $row['weapon_nametag'] : null,
                'stickers' => $stickers,
                'keychain' => $keychain,
            ];
        }
        return $selected;
    }

                                                                        
    // Label helpers (numeric/text only — no Tailwind)
                                                                        

    public static function wearLabelShort(float $wear): string
    {
        if ($wear <= 0.07) {
            return 'FN';
        }
        if ($wear <= 0.15) {
            return 'MW';
        }
        if ($wear <= 0.38) {
            return 'FT';
        }
        if ($wear < 0.45) {
            return 'WW';
        }
        return 'BS';
    }

    public static function teamLabel(int $team): string
    {
        return match ($team) {
            self::TEAM_T => Lang::t('skins.terrorists'),
            self::TEAM_CT => Lang::t('skins.counter_terrorists'),
            default => Lang::t('skins.both_teams'),
        };
    }

    public static function teamShort(int $team): string
    {
        return match ($team) {
            self::TEAM_T => 'T',
            self::TEAM_CT => 'CT',
            default => 'ALL',
        };
    }

                                                                        
    // i18n loader (from DataI18n) — EN base + translated overlay
                                                                        

    /**
     * Item-by-item merge of localized item data (<category>_<lang>.json) over
     * the English base (<category>_en.json). Translated packs are often
     * incomplete, so we always start from the full EN list and only swap in a
     * translated display name where one exists — nothing ever disappears.
     *
     * @param string   $category  'skins'|'agents'|'stickers'|'keychains'|'music'|'gloves'|'collectibles'
     * @param string   $nameField field holding the translatable text (e.g. 'paint_name')
     * @param string[] $keyFields fields that together identify a row across EN/translated
     */
    private static function load(string $category, string $nameField, array $keyFields, ?string $langCode = null): array
    {
        $langCode = $langCode ?? (class_exists(Lang::class) ? Lang::current() : 'en');
        $cacheKey = $category . ':' . $langCode;
        if (isset(self::$i18nCache[$cacheKey])) {
            return self::$i18nCache[$cacheKey];
        }

        $dir = BASE_PATH . '/app/Data/weaponpaints/';
        $en = self::readJson($dir . $category . '_en.json');

        if ($langCode === 'en') {
            return self::$i18nCache[$cacheKey] = $en;
        }

        $translated = self::readJson($dir . $category . '_' . $langCode . '.json');
        $lookup = [];
        foreach ($translated as $row) {
            $lookup[self::keyFor($row, $keyFields)] = $row;
        }

        $merged = [];
        foreach ($en as $row) {
            $localRow = $lookup[self::keyFor($row, $keyFields)] ?? null;
            if ($localRow && !empty($localRow[$nameField])) {
                $row[$nameField] = $localRow[$nameField];
            }
            $merged[] = $row;
        }

        return self::$i18nCache[$cacheKey] = $merged;
    }

    private static function keyFor(array $row, array $keyFields): string
    {
        $parts = [];
        foreach ($keyFields as $field) {
            $parts[] = (string) ($row[$field] ?? '');
        }
        return implode('|', $parts);
    }

    private static function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }
}
