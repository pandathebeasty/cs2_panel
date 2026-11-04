<?php

declare(strict_types=1);

namespace App\Core;

/**
 * UI-editable settings (panel_settings table). Overrides config.php.
 * Values are memoized for the duration of the request.
 *
 * In dev preview (no MySQL) settings are persisted to a small JSON file so the
 * UI reflects changes across requests just like the real DB would.
 */
final class Settings
{
    private static ?array $cache = null;
    private static bool $loadReliable = false;

    /** When set, writes are buffered here instead of hitting storage per key. */
    private static ?array $batch = null;

    /** Snapshot of stored values when the batch opened (for change diffing). */
    private static array $batchOriginal = [];

    /** Maintenance state must survive pruning but stay out of config snapshots. */
    private const RUNTIME_KEYS = [
        'db_schema_version',          // Schema: version the DB was last synced to
        'db_schema_attempt',          // Schema: timestamp of last sync attempt
        'last_maintenance_run',       // Maintenance: cron throttle timestamp
        'last_rarity_colors_refresh', // WeaponPaintsData: weekly refresh throttle
        'last_cron_run',              // Maintenance: last run triggered by /cron
        'backup_auto_last',           // Backup: last scheduled slot handled
        'backup_auto_status',         // Backup: result of the last scheduled run
        'monitor_last_sample',        // Monitor: last server sampling time
    ];

    /** Buffer a settings save so persistence and config sync run once. */
    public static function beginBatch(): void
    {
        self::$batch = [];
        // Prime the cache so reads during the batch see buffered values, and
        // snapshot the current values so we can report exactly what changed.
        self::$batchOriginal = self::load();
    }

    /**
     * Keys whose value actually changed since the batch opened (buffered value
     * differs from the stored one). Call BEFORE commitBatch(). Excludes keys
     * listed in $ignore (e.g. secrets you don't want to name in a log).
     *
     * @param string[] $ignore
     * @return string[] changed setting keys
     */
    public static function changedInBatch(array $ignore = []): array
    {
        $changed = [];
        foreach (self::$batch ?? [] as $key => $val) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            $old = self::$batchOriginal[$key] ?? null;
            if ((string) $old !== (string) $val) {
                $changed[] = $key;
            }
        }
        return $changed;
    }

    /** Persist all buffered writes in one shot, then close the batch. */
    public static function commitBatch(): void
    {
        $pairs = self::$batch ?? [];
        self::$batch = null;
        if (!$pairs) {
            return;
        }

        // Dev preview: merge into the JSON store with a single write.
        if (DevMode::enabled()) {
            $store = self::devStore();
            foreach ($pairs as $k => $v) {
                $store[$k] = $v;
            }
            self::writeDevStore($store);
            self::$cache = null;
            self::syncConfiguration(array_keys($pairs));
            return;
        }

        // Persist all pairs inside one transaction so it's a single commit.
        try {
            $pdo = Database::connection();
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            $values = implode(', ', array_fill(0, count($pairs), '(?, ?)'));
            $params = [];
            foreach ($pairs as $k => $v) {
                $params[] = $k;
                $params[] = $v;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO panel_settings (`key`, `value`) VALUES ' . $values
                . Database::onDuplicateUpdate(['value'], $pdo)
            );
            $stmt->execute($params);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if (defined('CS2PANEL_INSTALLED') && CS2PANEL_INSTALLED === true) {
                try {
                    $pdo = Database::connection();
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (\Throwable $ignore) {
                }
                throw $e;
            }
            // not installed yet; ignore
        }
        self::$cache = null;
        self::syncConfiguration(array_keys($pairs));
    }

    private static function devFile(): string
    {
        return BASE_PATH . '/.dev/settings.json';
    }

    private static function devStore(): array
    {
        $f = self::devFile();
        if (is_file($f)) {
            $data = json_decode((string) file_get_contents($f), true);
            self::$loadReliable = is_array($data);
            if (!self::$loadReliable) error_log('[CS2Panel] Settings: invalid development settings store.');
            return is_array($data) ? $data : [];
        }
        self::$loadReliable = true;
        return [];
    }

    private static function writeDevStore(array $store): void
    {
        if (!self::$loadReliable) {
            throw new \RuntimeException('Cannot update an unreadable development settings store.');
        }
        $contents = json_encode($store, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $file = self::devFile();
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Cannot create the development settings directory.');
        }
        if (file_put_contents($file, $contents, LOCK_EX) !== strlen($contents)) {
            throw new \RuntimeException('Cannot write the complete development settings store.');
        }
    }

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        // Dev preview: read from the JSON store instead of the DB.
        if (DevMode::enabled()) {
            return self::$cache = self::devStore();
        }
        // Before install there is no DB config -> do not touch the database at all
        if (!defined('CS2PANEL_INSTALLED') || CS2PANEL_INSTALLED !== true) {
            self::$loadReliable = false;
            return self::$cache = [];
        }
        try {
            if (!Database::tableExists('panel_settings')) {
                self::$loadReliable = false;
                return self::$cache = [];
            }
            $rows = Database::all('SELECT `key`, `value` FROM panel_settings');
        } catch (\Throwable $e) {
            self::$loadReliable = false;
            error_log('[CS2Panel] Settings load failed: ' . $e->getMessage());
            return self::$cache = [];
        }
        self::$loadReliable = true;
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        return self::$cache = $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $data = self::load();
        return $data[$key] ?? $default;
    }

    public static function migrateThemeMode(): void
    {
        if (self::get('theme_mode') === 'system') self::set('theme_mode', 'dark');
    }

    /** Persisted UI values and new defaults, excluding maintenance counters. */
    public static function configurationSnapshot(): ?array
    {
        if (self::$batch !== null) {
            throw new \LogicException('Commit settings before synchronizing configuration.');
        }
        self::$cache = null;
        $stored = self::load();
        if (!self::$loadReliable) return null;
        $snapshot = array_replace(self::defaults(), $stored);
        foreach (self::RUNTIME_KEYS as $key) unset($snapshot[$key]);
        ksort($snapshot);
        return $snapshot;
    }

    private static function syncConfiguration(array $keys): void
    {
        if (!array_diff($keys, self::RUNTIME_KEYS) || !is_file(BASE_PATH . '/config/config.php')) return;
        Config::syncFromSettings();
    }

    /**
     * Every setting the panel knows about, with its built-in default. This is
     * the single source of truth used to materialise the whole configuration
     * in panel_settings (see seedDefaults) so a panel can be moved by copying
     * the database alone.
     *
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        $d = [
                      
            'site_name'        => 'CS2 Panel',
            'website_url'      => '',
            'per_page'         => '25',
            'timezone'         => (string) Config::get('app.timezone', 'UTC'),
            'site_language'    => (string) Config::get('app.locale', 'en'),
            'steam_api_key'    => '',
            'login_days'       => '30',
            'test_mode'        => '0',
            'logo_path'        => '',
            'favicon_path'     => '',
            'forum_url'        => '',
            'cron_token'       => '',
                         
            'theme_accent'     => '#e01e37',
            'theme_name'       => 'default',
            'theme_mode'       => 'dark',
            'custom_themes'    => '[]',
                                      
            'custom_flags'         => '',
            'admin_ignored_groups' => '',
                      
            'vip_enabled'        => '1',
            'vip_groups'         => '',
            'vip_ignored_groups' => '',
            'vip_time_mode'      => '3',
            // WeaponPaints / Skins (loadout editor backed by the wp_player_* tables).
            // Standalone toggle (like store_enabled); the prefix is configurable so
            // it can match whatever the WeaponPaints plugin uses.
            'skins_enabled'      => '0',
            'wp_prefix'          => (string) Config::get('weaponpaints.prefix', 'wp_'),
            'chat_logs_enabled'  => '0',
            'chat_logs_table'    => (string) Config::get('chat_logger.table', 'chat_logs'),
            // Stats backend: which plugin provides ranks/stats/playtime.
            // 'zenith' (K4-Zenith JSON storage) or 'k4system' (K4-System flat tables).
            'stats_backend'       => (string) Config::get('stats.backend', 'zenith'),
            'stats_enabled'       => Config::get('stats.enabled', true) ? '1' : '0',
                        
            'zenith_enabled'      => '1',
            'zenith_ranks'        => (string) Config::get('zenith.ranks_table', 'zenith_ranks'),
            'zenith_stats'        => (string) Config::get('zenith.stats_table', 'zenith_stats'),
            'zenith_storage'      => (string) Config::get('zenith.storage_table', 'zenith_player_storage'),
            'zenith_steam'        => (string) Config::get('zenith.steam_column', 'steam_id'),
            'zenith_weapon_stats' => '',
            'zenith_map_stats'    => '',
            // K4-System (flat tables: <prefix>k4ranks / k4stats / k4times).
            'k4_prefix'           => (string) Config::get('k4system.prefix', ''),
            'k4_ranks_table'      => (string) Config::get('k4system.ranks_table', 'k4ranks'),
            'k4_stats_table'      => (string) Config::get('k4system.stats_table', 'k4stats'),
            'k4_times_table'      => (string) Config::get('k4system.times_table', 'k4times'),
            'k4_steam'            => (string) Config::get('k4system.steam_column', 'steam_id'),
            // Rank display style for K4-System: 'competitive' (CS:GO skillgroups) or 'premier'.
            'k4_rank_type'        => (string) Config::get('k4system.rank_type', 'competitive'),
                       
            'contests_enabled'       => '1',
            'contest_webhook'        => '',
            'contest_retention_days' => '90',
                    
            'store_enabled'                   => '0',
            'store_currency'                  => 'EUR',
            'store_paypal_mode'               => 'sandbox',
            'store_paypal_sandbox_client_id'  => '',
            'store_paypal_sandbox_secret'     => '',
            'store_paypal_sandbox_webhook_id' => '',
            'store_paypal_live_client_id'     => '',
            'store_paypal_live_secret'        => '',
            'store_paypal_live_webhook_id'    => '',
            'store_fee_percent'               => '3.4',
            'store_fee_fixed'                 => '0.35',
            'store_pass_fee_to_buyer'         => '1',
            'store_pending_cancel_minutes'    => '10',
            'store_packages'                  => '[]',
            // Store credits (schwarper/cs2-store integration)
            'store_credits_enabled'           => '0',
            'store_players_table'             => 'store_players',
            'store_credit_packages'           => '[]',
            'store_donations_enabled'         => '0',
            'store_donation_default'          => '1.00',
            'store_donation_min'              => '1.00',
            'cart_ttl_minutes'                => '10',
            'store_codes_enabled'             => '0',
            'store_checkout_discount_enabled' => '0',
            'store_checkout_discount_percent' => '0',
            // Community pages
            'appeals_enabled' => '1',
            'reports_enabled' => '1',
            // Benefits page
            'benefits_enabled' => '0',
                   
            'mail_from'        => '',
            'mail_from_name'   => '',
            'mail_smtp_host'   => '',
            'mail_smtp_port'   => '587',
            'mail_smtp_user'   => '',
            'mail_smtp_pass'   => '',
            'mail_smtp_secure' => 'tls',
                      
            'discord_enabled' => '0',
            'discord_log_all' => '1',
            'discord_webhook' => '',
            'discord_invite'  => '',
            // Scheduled database backups (db_backups/backup_auto_*).
            'backup_auto_enabled'   => '0',
            'backup_auto_frequency' => 'daily',
            'backup_auto_time'      => '03:00',
            'backup_auto_weekday'   => '1',
            'backup_auto_keep'      => '7',
            'backup_auto_compress'  => '1',
        'backup_max_age_days'   => '0',
            // Server monitoring (A2S samples, uptime, down/up alerts).
            'monitor_enabled'        => '1',
            'monitor_interval'       => '2',
            'monitor_down_after'     => '3',
            'monitor_retention_days' => '30',
            'monitor_webhook'        => '',
            // Security and audit.
            'security_require_2fa'  => '0',
            'audit_retention_days'  => '0',
        ];

        foreach (array_keys(Discord::CATEGORIES) as $cat) {
            $d['discord_notify_' . $cat] = '1';
        }
        foreach (array_keys(Store::testFeatures()) as $feat) {
            $d['test_feat_' . $feat] = '0';
        }
        $d += IntegrationDb::defaults();

        return $d;
    }

    /**
     * Write any setting that has no row yet using its built-in default, so the
     * database always holds the complete configuration. Existing values are
     * never overwritten. Returns the number of keys inserted.
     */
    public static function seedDefaults(): int
    {
        $current = self::load();
        $missing = [];
        foreach (self::defaults() as $key => $value) {
            if (!array_key_exists($key, $current)) {
                $missing[$key] = $value;
            }
        }
        if (!$missing) {
            return 0;
        }

        // SAFETY GUARD: a transient read failure in load() returns an EMPTY
        // array (caught + cached as []), which would make EVERY key look
        // "missing" and seed the whole default set. Before trusting an empty
        // read, confirm the table is really empty by COUNT(*). If it actually
        // holds rows, the read lied — abort seeding so we never clobber live
        // settings with defaults. (Without this, a bad read used to overwrite
        // every setting with its default via INSERT ... ON DUPLICATE KEY UPDATE.)
        if (!DevMode::enabled()
            && (!defined('CS2PANEL_INSTALLED') || CS2PANEL_INSTALLED === true)) {
            try {
                if (Database::tableExists('panel_settings')) {
                    $have = (int) Database::scalar('SELECT COUNT(*) FROM panel_settings');
                    // If the live table has substantially more rows than our
                    // in-memory read saw, the read is unreliable — do not seed.
                    if ($have > count($current)) {
                        error_log('[CS2Panel] seedDefaults aborted: table has '
                            . $have . ' rows but load() saw ' . count($current)
                            . ' — refusing to overwrite live settings.');
                        self::$cache = null; // force a fresh read next time
                        return 0;
                    }
                }
            } catch (\Throwable $e) {
                // Can't verify safely -> do not seed (fail closed).
                error_log('[CS2Panel] seedDefaults safety check failed: ' . $e->getMessage());
                return 0;
            }
        }

        // Seeding is a convenience, never a reason to fail the page. Use a
        // pure INSERT that SKIPS existing keys (INSERT IGNORE), so seeding can
        // only ADD missing rows and can never overwrite a value already stored.
        try {
            if (DevMode::enabled()) {
                self::beginBatch();
                foreach ($missing as $key => $value) {
                    self::set($key, $value);
                }
                self::commitBatch();
            } else {
                $pdo = Database::connection();
                $values = implode(', ', array_fill(0, count($missing), '(?, ?)'));
                $params = [];
                foreach ($missing as $k => $v) {
                    $params[] = $k;
                    $params[] = $v;
                }
                $stmt = $pdo->prepare(
                    'INSERT IGNORE INTO panel_settings (`key`, `value`) VALUES ' . $values
                );
                $stmt->execute($params);
                self::$cache = null;
                self::syncConfiguration(array_keys($missing));
            }
        } catch (\Throwable $e) {
            self::$batch = null;
            error_log('[CS2Panel] settings seeding failed: ' . $e->getMessage());
            return 0;
        }
        return count($missing);
    }

    /**
     * The complete set of setting keys the panel legitimately uses: every key
     * from defaults() (including the dynamic discord_notify_* / test_feat_*
     * ones) plus the runtime bookkeeping keys. prune() treats anything NOT in
     * this set as a dead/orphan row.
     *
     * @return array<string,true> key => true, for O(1) membership checks
     */
    public static function knownKeys(): array
    {
        $known = [];
        foreach (array_keys(self::defaults()) as $k) {
            $known[$k] = true;
        }
        foreach (self::RUNTIME_KEYS as $k) {
            $known[$k] = true;
        }
        return $known;
    }

    /**
     * Delete every panel_settings row whose key is no longer used by the panel
     * (not produced by defaults() and not a runtime key). This keeps the table
     * clean as settings are removed over time — e.g. the pre-split PayPal keys
     * or abandoned contest cron keys. Returns the keys it removed.
     *
     * Self-maintaining: the valid set is derived from defaults() + RUNTIME_KEYS,
     * so there is no orphan list to keep in sync by hand. Never throws; a prune
     * failure must never take a page down.
     *
     * @return string[] keys deleted
     */
    public static function prune(): array
    {
        // Dev preview uses a JSON store; prune it in memory.
        if (DevMode::enabled()) {
            $store = self::devStore();
            $known = self::knownKeys();
            $dead = array_values(array_filter(array_keys($store), static fn($k) => !isset($known[$k])));
            if (!$dead) {
                return [];
            }
            foreach ($dead as $k) {
                unset($store[$k]);
            }
            self::writeDevStore($store);
            self::$cache = null;
            self::syncConfiguration($dead);
            return $dead;
        }

        if (!defined('CS2PANEL_INSTALLED') || CS2PANEL_INSTALLED !== true) {
            return [];
        }

        try {
            if (!Database::tableExists('panel_settings')) {
                return [];
            }
            $rows = Database::all('SELECT `key` FROM panel_settings');
            $known = self::knownKeys();
            $dead = [];
            foreach ($rows as $r) {
                $k = (string) $r['key'];
                if (!isset($known[$k])) {
                    $dead[] = $k;
                }
            }
            if (!$dead) {
                return [];
            }
            // SAFETY: if "everything" looks dead, the read is almost certainly
            // broken (same failure mode that once wiped settings). Refuse to
            // delete the whole table.
            if (count($dead) >= count($rows)) {
                error_log('[CS2Panel] settings prune aborted: every row looked dead ('
                    . count($dead) . '/' . count($rows) . ') — refusing to wipe the table.');
                return [];
            }
            $placeholders = implode(', ', array_fill(0, count($dead), '?'));
            Database::run('DELETE FROM panel_settings WHERE `key` IN (' . $placeholders . ')', $dead);
            self::$cache = null;
            self::syncConfiguration($dead);
            error_log('[CS2Panel] settings prune removed ' . count($dead) . ' dead key(s): ' . implode(', ', $dead));
            return $dead;
        } catch (\Throwable $e) {
            error_log('[CS2Panel] settings prune failed: ' . $e->getMessage());
            return [];
        }
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::get($key);
        if ($val === null) {
            return $default;
        }
        return $val === '1' || $val === 'true' || $val === 1;
    }

    public static function set(string $key, string $value): void
    {
        if ($key === 'theme_mode' && !in_array($value, Themes::MODES, true)) {
            error_log('[CS2Panel] Unsupported theme mode rejected.');
            throw new \InvalidArgumentException('Theme mode must be dark or light.');
        }
        // Inside a batch: buffer the write and reflect it in the cache so
        // reads within the same request stay consistent. commitBatch() flushes.
        if (self::$batch !== null) {
            self::$batch[$key] = $value;
            if (self::$cache !== null) {
                self::$cache[$key] = $value;
            }
            return;
        }

        // Dev preview: persist to the JSON store so changes survive requests.
        if (DevMode::enabled()) {
            $store = self::devStore();
            $store[$key] = $value;
            self::writeDevStore($store);
            self::$cache = null;
            self::syncConfiguration([$key]);
            return;
        }

        try {
            Database::run(
                'INSERT INTO panel_settings (`key`, `value`) VALUES (?, ?)'
                . Database::onDuplicateUpdate(['value']),
                [$key, $value]
            );
        } catch (\Throwable $e) {
            if (!defined('CS2PANEL_INSTALLED') || CS2PANEL_INSTALLED !== true) {
                // not installed yet; ignore
            } else {
                throw $e;
            }
        }
        self::$cache = null; // invalidate the cache
        self::syncConfiguration([$key]);
    }
}
