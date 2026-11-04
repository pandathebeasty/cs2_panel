<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Global config access with support for nested keys (dot notation).
 */
final class Config
{
    private static array $data = [];

    public static function init(array $data): void
    {
        self::$data = $data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data;
        foreach (explode('.', $key) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }
        return $value;
    }

    public static function all(): array
    {
        return self::$data;
    }

    /**
     * Set a nested config value by dot-key (e.g. 'steam.realm'), creating
     * intermediate arrays as needed. Only mutates the in-memory array.
     */
    private static function setNested(array &$data, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &$data;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                return;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
    }

    /** Mirror persisted settings and new defaults; unchanged files stay untouched. */
    public static function syncFromSettings(): bool
    {
        $file = BASE_PATH . '/config/config.php';
        if (!is_file($file)) {
            return false;
        }
        if (!DevMode::enabled() && defined('CS2PANEL_INSTALLED') && CS2PANEL_INSTALLED
            && Database::connection()->inTransaction()) {
            error_log('[CS2Panel] Configuration sync deferred until the outer settings transaction commits.');
            return false;
        }
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) throw new \RuntimeException('Cannot open the configuration lock.');
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException('Cannot lock the configuration file.');
        }
        try {
            clearstatcache(true, $file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            $current = require $file;
            if (!is_array($current)) {
                throw new \RuntimeException('The panel configuration must return an array.');
            }
            self::$data = $current;
            return self::synchronizeSettings($file);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function synchronizeSettings(string $file): bool
    {
        if (!is_writable($file)) {
            throw new \RuntimeException('The panel configuration file is not writable.');
        }
        $snapshot = Settings::configurationSnapshot();
        if ($snapshot === null) {
            throw new \RuntimeException('Settings could not be read reliably; configuration was left unchanged.');
        }

        $S = static fn(string $k, mixed $d = null): mixed => Settings::get($k, $d);

        $siteUrl = rtrim(trim((string) $S('website_url', '')), '/');

        // Missing settings leave legacy config paths intact.
        $map = [];

        if ($siteUrl !== '') {
            $map['steam.realm'] = $siteUrl;
            $map['steam.return_url'] = $siteUrl . '/auth/steam/return';
        }

        $apiKey = $S('steam_api_key', null);
        if ($apiKey !== null) {
            $map['steam.api_key'] = (string) $apiKey;
        }

        $tz = (string) $S('timezone', '');
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            $map['app.timezone'] = $tz;
        }

        // Lang::boot() may need the locale before settings are available.
        $lang = (string) $S('site_language', '');
        if ($lang !== '' && isset(\App\Core\Lang::AVAILABLE[$lang])) {
            $map['app.locale'] = $lang;
        }

        $perPage = $S('per_page', null);
        if ($perPage !== null && $perPage !== '') {
            $map['app.per_page'] = max(5, min(200, (int) $perPage));
        }

        $backend = $S('stats_backend', null);
        if ($backend !== null && $backend !== '') {
            $map['stats.backend'] = ($backend === 'k4system') ? 'k4system' : 'zenith';
        }

        $statsEnabled = $S('stats_enabled', null);
        if ($statsEnabled !== null) {
            $map['stats.enabled'] = ($statsEnabled === '1' || $statsEnabled === 1 || $statsEnabled === true);
        }

        $zenithEnabled = $S('zenith_enabled', null);
        if ($zenithEnabled !== null) {
            $map['zenith.enabled'] = ($zenithEnabled === '1' || $zenithEnabled === 1 || $zenithEnabled === true);
        }
        foreach ([
            'zenith_ranks'   => 'zenith.ranks_table',
            'zenith_stats'   => 'zenith.stats_table',
            'zenith_storage' => 'zenith.storage_table',
            'zenith_steam'   => 'zenith.steam_column',
        ] as $sk => $ck) {
            $v = $S($sk, null);
            if ($v !== null && $v !== '') {
                $map[$ck] = (string) $v;
            }
        }

        foreach ([
            'k4_prefix'      => 'k4system.prefix',
            'k4_ranks_table' => 'k4system.ranks_table',
            'k4_stats_table' => 'k4system.stats_table',
            'k4_times_table' => 'k4system.times_table',
            'k4_steam'       => 'k4system.steam_column',
            'k4_rank_type'   => 'k4system.rank_type',
        ] as $sk => $ck) {
            $v = $S($sk, null);
            if ($v !== null && ($v !== '' || $sk === 'k4_prefix')) {
                $map[$ck] = (string) $v;
            }
        }

        $skinsEnabled = $S('skins_enabled', null);
        if ($skinsEnabled !== null) {
            $map['weaponpaints.enabled'] = ($skinsEnabled === '1' || $skinsEnabled === 1 || $skinsEnabled === true);
        }
        $wpPrefix = $S('wp_prefix', null);
        if ($wpPrefix !== null) {
            $map['weaponpaints.prefix'] = (string) $wpPrefix;
        }

        $chatLogsEnabled = $S('chat_logs_enabled', null);
        if ($chatLogsEnabled !== null) {
            $map['chat_logger.enabled'] = ($chatLogsEnabled === '1' || $chatLogsEnabled === 1 || $chatLogsEnabled === true);
        }
        $chatLogsTable = $S('chat_logs_table', null);
        if ($chatLogsTable !== null) {
            $map['chat_logger.table'] = (string) $chatLogsTable;
        }

        $vipEnabled = $S('vip_enabled', null);
        if ($vipEnabled !== null) {
            $map['vipcore.enabled'] = ($vipEnabled === '1' || $vipEnabled === 1 || $vipEnabled === true);
        }
        $siteName = $S('site_name', null);
        if ($siteName !== null && $siteName !== '') $map['app.name'] = (string) $siteName;

        // Separate databases per integration; replaced as a whole so disabled
        // integrations disappear from the file.
        $integrationDb = IntegrationDb::configFromSettings();
        if ($integrationDb !== null) {
            $map['integration_db'] = $integrationDb;
        }

        $data = self::$data;
        $changed = false;
        foreach ($map as $key => $value) {
            if (self::get($key) !== $value) {
                if ($key === 'integration_db' && $value === [] && !array_key_exists('integration_db', $data)) {
                    continue;
                }
                self::setNested($data, $key, $value);
                $changed = true;
            }
        }
        if (($data['panel_settings'] ?? null) !== $snapshot) {
            $data['panel_settings'] = $snapshot;
            $changed = true;
        }
        if (!$changed) {
            return false;
        }

        $contents = "<?php\n\n// Updated by Settings on " . date('Y-m-d H:i:s') . "\n\nreturn "
            . var_export($data, true) . ";\n";
        self::writeConfiguration($file, $contents);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        self::$data = $data;
        Database::resetRouting();
        return true;
    }

    private static function writeConfiguration(string $file, string $contents): void
    {
        $temporary = null;
        $stream = null;
        try {
            $path = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $stream = @fopen($path, 'x+b');
            if ($stream === false) {
                throw new \RuntimeException('Cannot create the temporary configuration file.');
            }
            $temporary = $path;
            $permissions = fileperms($file);
            if ($permissions === false || !chmod($temporary, $permissions & 0777)) {
                throw new \RuntimeException('Cannot preserve configuration permissions.');
            }
            if (fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) {
                throw new \RuntimeException('Cannot write the complete configuration.');
            }
            fclose($stream);
            $stream = null;
            if (!@rename($temporary, $file)) throw new \RuntimeException('Cannot replace the configuration file.');
            $temporary = null;
        } finally {
            if (is_resource($stream)) fclose($stream);
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
        }
    }

    /**
     * Backwards-compatible shim: syncing the Steam base URL now goes through the
     * full {@see self::syncFromSettings()} path.
     */
    public static function updateSteamBaseUrl(string $baseUrl): bool
    {
        return self::syncFromSettings();
    }
}
