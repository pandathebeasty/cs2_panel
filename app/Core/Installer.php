<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Setup wizard: detects whether the panel is installed, tests the DB, writes
 * config/config.php, runs the panel schema and creates the first root.
 */
final class Installer
{
    /** The panel is installed if config/config.php exists. */
    public static function isInstalled(): bool
    {
        return is_file(BASE_PATH . '/config/config.php');
    }

    /**
     * Test the DB connection with the given data. Returns PDO or throws.
     */
    public static function testConnection(array $db): PDO
    {
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
        return new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * extdb_* settings from the wizard. Each ticked integration is validated and
     * its connection tested; blank host/port/user fall back to the main
     * database, and a blank password too when the user is the main one.
     * @param array<string,mixed> $input
     * @param string[] $errors
     * @return array<string,string>
     */
    public static function integrationDbInput(array $input, PDO $main, array &$errors): array
    {
        $out = [];
        foreach (IntegrationDb::KEYS as $k => $label) {
            $enabled = !empty($input["extdb_{$k}_enabled"]);
            $host = trim((string) ($input["extdb_{$k}_host"] ?? ''));
            $port = (int) ($input["extdb_{$k}_port"] ?? 0);
            $name = trim((string) ($input["extdb_{$k}_name"] ?? ''));
            $user = trim((string) ($input["extdb_{$k}_user"] ?? ''));
            $pass = (string) ($input["extdb_{$k}_pass"] ?? '');
            if ($enabled) {
                $host = $host !== '' ? $host : trim((string) ($input['db_host'] ?? ''));
                $port = $port > 0 ? $port : ((int) ($input['db_port'] ?? 0) ?: 3306);
                $user = $user !== '' ? $user : trim((string) ($input['db_user'] ?? ''));
                if ($pass === '' && $user === trim((string) ($input['db_user'] ?? ''))) {
                    $pass = (string) ($input['db_password'] ?? '');
                }
            }
            $port = $port >= 1 && $port <= 65535 ? $port : 3306;

            if ($enabled) {
                if ($host === '' || $name === '' || $user === ''
                    || !preg_match('/^[A-Za-z0-9.\-_:\[\]]{1,255}$/', $host)
                    || !preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $name)
                    || strlen($user) > 80) {
                    $errors[] = Lang::t('settings.extdb_invalid', ['name' => $label]);
                } else {
                    $test = Database::test(['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'password' => $pass]);
                    if (!$test['ok']) {
                        $errors[] = Lang::t('settings.extdb_connect_failed', ['name' => $label]) . ' ' . $test['error'];
                    } elseif (in_array($k, IntegrationDb::CROSS_WRITE_KEYS, true)
                        && (empty($test['xa']) || !Database::supportsXa($main))) {
                        $errors[] = Lang::t('settings.extdb_xa_required', ['name' => $label]);
                    }
                }
            }
            $out["extdb_{$k}_enabled"] = $enabled ? '1' : '0';
            $out["extdb_{$k}_host"] = $enabled ? $host : '';
            $out["extdb_{$k}_port"] = (string) $port;
            $out["extdb_{$k}_name"] = $enabled ? $name : '';
            $out["extdb_{$k}_user"] = $enabled ? $user : '';
            $out["extdb_{$k}_pass"] = $enabled ? $pass : '';
        }
        return $out;
    }

    public static function hasPanelSettings(PDO $pdo): bool
    {
        $statement = $pdo->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'panel_settings' LIMIT 1"
        );
        return $statement->fetchColumn() !== false;
    }

    /**
     * Generate the config/config.php contents from the wizard input.
     */
    public static function buildConfig(array $input): string
    {
        $export = static fn(mixed $v): string => var_export($v, true);

        $superAdmins = array_values(array_filter(array_map('trim', explode(',', $input['super_admins'] ?? ''))));

        $data = [
            'db' => [
                'host'     => $input['db_host'],
                'port'     => (int) $input['db_port'],
                'name'     => $input['db_name'],
                'user'     => $input['db_user'],
                'password' => $input['db_password'],
                'charset'  => 'utf8mb4',
            ],
            'sa_prefix' => $input['sa_prefix'] ?: 'sa_',
            // Which plugin supplies ranks/stats/playtime: 'zenith' or 'k4system'.
            'stats' => [
                'backend' => (($input['stats_backend'] ?? 'zenith') === 'k4system') ? 'k4system' : 'zenith',
                'enabled' => !empty($input['zenith_enabled']),
            ],
            'zenith' => [
                'enabled'       => !empty($input['zenith_enabled']),
                'storage_table' => $input['zenith_storage'] ?: 'zenith_player_storage',
                'stats_table'   => $input['zenith_stats'] ?: 'zenith_stats',
                'ranks_table'   => $input['zenith_ranks'] ?: 'zenith_ranks',
                'steam_column'  => $input['zenith_steam_col'] ?: 'steam_id',
            ],
            // K4-System (flat tables). Prefix defaults to empty (plugin default).
            'k4system' => [
                'prefix'       => $input['k4_prefix'] ?? '',
                'ranks_table'  => $input['k4_ranks_table'] ?: 'k4ranks',
                'stats_table'  => $input['k4_stats_table'] ?: 'k4stats',
                'times_table'  => $input['k4_times_table'] ?: 'k4times',
                'steam_column' => $input['k4_steam'] ?: 'steam_id',
                'rank_type'    => (($input['k4_rank_type'] ?? 'competitive') === 'premier') ? 'premier' : 'competitive',
                'missions'          => false,
                'achievements'      => false,
                'seasons'           => false,
                'missions_timezone' => 'Europe/Istanbul',
            ],
            'vipcore' => [
                'enabled'       => !empty($input['vip_enabled']),
                'users_table'   => 'vip_users',
                'servers_table' => 'vip_servers',
            ],
            // WeaponPaints "Skins" loadout editor (reads/writes wp_player_* tables).
            'weaponpaints' => [
                'enabled' => !empty($input['skins_enabled']),
                'prefix'  => ($input['wp_prefix'] ?? '') ?: 'wp_',
            ],
            'steam' => [
                'api_key'    => $input['steam_api_key'] ?? '',
                'realm'      => rtrim($input['realm'], '/'),
                'return_url' => rtrim($input['realm'], '/') . '/auth/steam/return',
            ],
            'super_admins' => $superAdmins,
            'app' => [
                'name'     => $input['site_name'] ?: 'CS2 Panel',
                'timezone' => $input['timezone'] ?: 'UTC',
                'locale'   => in_array($input['site_language'] ?? '', ['en', 'ro', 'ru', 'de'], true) ? $input['site_language'] : 'en',
                'debug'    => false,
                'per_page' => 25,
            ],
            'rcon' => ['timeout' => 3],
        ];

        return "<?php\n\n// Generated by the setup wizard at " . date('Y-m-d H:i:s') . "\n\nreturn "
            . $export($data) . ";\n";
    }

    public static function writeConfig(string $contents): bool
    {
        $dir = BASE_PATH . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($dir . '/config.php', $contents) !== false;
    }

    /**
     * Run the panel schema (panel_users + panel_settings).
     */
    public static function runPanelSchema(PDO $pdo): void
    {
        $sql = file_get_contents(BASE_PATH . '/database/panel_schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('Cannot find database/panel_schema.sql');
        }

                                                                             
        // Leading SQL comments would otherwise cause CREATE chunks to be skipped.
        $lines = preg_split('/\R/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue; // drop comment-only lines
            }
            $clean[] = $line;
        }
        $sql = implode("\n", $clean);

        // Execute each statement one by one.
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }

        // A fresh install must also carry every shipped migration, otherwise
        // the new tables only appear later, lazily.
        try {
            Migrator::runPending(null, $pdo);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] install migrations failed: ' . $e->getMessage());
        }
    }

    /**
     * Create the first (owner) panel user — the person configuring the panel.
     * Owner is the only full-access role; everyone else added later is a
     * moderator.
     */
    public static function createRoot(PDO $pdo, string $steamId64, string $name): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO panel_users (steamid64, name, role, can_manage) VALUES (?, ?, 'owner', 1)
             ON DUPLICATE KEY UPDATE role = 'owner', can_manage = 1"
        );
        $stmt->execute([$steamId64, $name]);
    }

       
                                                                            
       
    /** @param array<string,string> $extdb validated output of integrationDbInput() */
    public static function saveInitialSettings(PDO $pdo, array $input, bool $preserveExisting = false, array $extdb = []): void
    {
        $webhook = trim((string) ($input['discord_webhook'] ?? ''));

        // VIP groups (optional). Normalize to one-per-line, trimmed, de-duplicated.
        // Empty is fine — the admin can define them later in Settings.
        $groupsRaw = (string) ($input['vip_groups'] ?? '');
        $groups = preg_split('/[\r\n,]+/', $groupsRaw) ?: [];
        $groups = array_values(array_unique(array_filter(array_map('trim', $groups), static fn($g) => $g !== '')));

        $backend = (($input['stats_backend'] ?? 'zenith') === 'k4system') ? 'k4system' : 'zenith';
        $rankType = (($input['k4_rank_type'] ?? 'competitive') === 'premier') ? 'premier' : 'competitive';

        $pairs = [
            'site_name'       => $input['site_name'] ?: 'CS2 Panel',
            'website_url'     => rtrim((string) ($input['realm'] ?? ''), '/'),
            'discord_webhook' => $webhook,
            'discord_enabled' => $webhook !== '' ? '1' : '0',
            'vip_groups'      => implode("\n", $groups),
            // Stats backend selection + per-backend table config. The two
            // backends are mutually exclusive, so zenith_enabled follows the
            // backend choice (and the master enable checkbox).
            'stats_backend'   => $backend,
            'stats_enabled'   => !empty($input['zenith_enabled']) ? '1' : '0',
            'zenith_enabled'  => (!empty($input['zenith_enabled']) && $backend === 'zenith') ? '1' : '0',
            'zenith_ranks'    => $input['zenith_ranks'] ?: 'zenith_ranks',
            'zenith_stats'    => $input['zenith_stats'] ?: 'zenith_stats',
            'zenith_storage'  => $input['zenith_storage'] ?: 'zenith_player_storage',
            'zenith_steam'    => $input['zenith_steam_col'] ?: 'steam_id',
            'k4_prefix'       => (string) ($input['k4_prefix'] ?? ''),
            'k4_ranks_table'  => $input['k4_ranks_table'] ?: 'k4ranks',
            'k4_stats_table'  => $input['k4_stats_table'] ?: 'k4stats',
            'k4_times_table'  => $input['k4_times_table'] ?: 'k4times',
            'k4_steam'        => $input['k4_steam'] ?: 'steam_id',
            'k4_rank_type'    => $rankType,
            // WeaponPaints "Skins" module.
            'skins_enabled'   => !empty($input['skins_enabled']) ? '1' : '0',
            'wp_prefix'       => ($input['wp_prefix'] ?? 'wp_') ?: 'wp_',
            // Site-wide panel language (owner-chosen).
            'site_language'   => in_array($input['site_language'] ?? '', ['en', 'ro', 'ru', 'de'], true) ? $input['site_language'] : 'en',
        ];
        // Materialise every known setting first, so the fresh install already
        // has a complete panel_settings table (a copy of the DB is portable).
        $pairs = array_merge(Settings::defaults(), $pairs);
        $upsert = 'INSERT INTO panel_settings (`key`, `value`) VALUES (?, ?)'
            . Database::onDuplicateUpdate(['value'], $pdo);

        $stmt = $pdo->prepare($preserveExisting ? 'INSERT IGNORE INTO panel_settings (`key`, `value`) VALUES (?, ?)' : $upsert);
        foreach (array_diff_key($pairs, $extdb) as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        // The separate-database choices were just validated in the wizard, so
        // they replace any older values even when existing settings are kept.
        $stmt = $pdo->prepare($upsert);
        foreach ($extdb as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    }
}
