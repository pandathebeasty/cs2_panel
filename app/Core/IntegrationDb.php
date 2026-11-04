<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Optional separate database per plugin integration.
 *
 * Settings keys: extdb_<key>_enabled / _host / _port / _name / _user / _pass.
 * Config::syncFromSettings() turns the enabled ones into config
 * `integration_db.<key>` (credentials + owned tables/prefixes) which
 * Database uses to route queries.
 */
final class IntegrationDb
{
    /** integration key => display name */
    public const KEYS = [
        'simpleadmin'  => 'CS2-SimpleAdmin',
        'vipcore'      => 'VIP Core',
        'zenith'       => 'K4-Zenith',
        'k4system'     => 'K4-System',
        'weaponpaints' => 'WeaponPaints',
        'chatlogs'     => 'Chat Logger',
        'store'        => 'Store credits',
    ];

    public const FIELDS = ['enabled', 'host', 'port', 'name', 'user', 'pass'];

    /** Integrations whose tables are written together with panel tables (Appeals, Reports). */
    public const CROSS_WRITE_KEYS = ['simpleadmin'];

    /** @return array<string,string> setting key => default */
    public static function defaults(): array
    {
        $d = [];
        foreach (array_keys(self::KEYS) as $k) {
            $cfg = Config::get('integration_db.' . $k, []);
            $cfg = is_array($cfg) ? $cfg : [];
            $d["extdb_{$k}_enabled"] = $cfg ? '1' : '0';
            $d["extdb_{$k}_host"]    = (string) ($cfg['host'] ?? '');
            $d["extdb_{$k}_port"]    = (string) ($cfg['port'] ?? '3306');
            $d["extdb_{$k}_name"]    = (string) ($cfg['name'] ?? '');
            $d["extdb_{$k}_user"]    = (string) ($cfg['user'] ?? '');
            $d["extdb_{$k}_pass"]    = (string) ($cfg['password'] ?? '');
        }
        return $d;
    }

    /**
     * Tables (exact names) and prefixes owned by an integration, from the
     * current settings/config.
     * @return array{tables:string[], prefixes:string[]}
     */
    public static function ownedTables(string $key): array
    {
        $S = static fn(string $k, string $d): string => trim((string) (Settings::get($k, $d) ?? $d));
        $tables = [];
        $prefixes = [];
        switch ($key) {
            case 'simpleadmin':
                $p = trim((string) Config::get('sa_prefix', 'sa_'));
                if ($p !== '') {
                    $prefixes[] = $p;
                }
                break;
            case 'vipcore':
                $tables[] = (string) Config::get('vipcore.users_table', 'vip_users');
                $tables[] = (string) Config::get('vipcore.servers_table', 'vip_servers');
                break;
            case 'zenith':
                $storage = $S('zenith_storage', (string) Config::get('zenith.storage_table', 'zenith_player_storage'));
                $prefix = 'zenith_';
                if (($pos = strpos($storage, 'player_storage')) !== false) {
                    $prefix = substr($storage, 0, $pos);
                }
                $map = $S('zenith_map_stats', '');
                $weapon = $S('zenith_weapon_stats', '');
                $tables = [
                    $storage,
                    $S('zenith_ranks', (string) Config::get('zenith.ranks_table', 'zenith_ranks')),
                    $S('zenith_stats', (string) Config::get('zenith.stats_table', 'zenith_stats')),
                    $map !== '' ? $map : $prefix . 'map_stats',
                    $weapon !== '' ? $weapon : $prefix . 'weapon_stats',
                ];
                break;
            case 'k4system':
                $p = $S('k4_prefix', (string) Config::get('k4system.prefix', ''));
                $tables = [
                    $p . $S('k4_ranks_table', 'k4ranks'),
                    $p . $S('k4_stats_table', 'k4stats'),
                    $p . $S('k4_times_table', 'k4times'),
                ];
                break;
            case 'weaponpaints':
                $p = $S('wp_prefix', (string) Config::get('weaponpaints.prefix', 'wp_'));
                foreach (['player_skins', 'player_knife', 'player_gloves', 'player_agents', 'player_music', 'player_pins'] as $t) {
                    $tables[] = $p . $t;
                }
                break;
            case 'chatlogs':
                $tables[] = $S('chat_logs_table', (string) Config::get('chat_logger.table', 'chat_logs'));
                break;
            case 'store':
                $tables[] = $S('store_players_table', 'store_players');
                break;
        }
        $valid = static fn(string $t): bool => (bool) preg_match('/^[A-Za-z0-9_$]+$/', $t) && !str_starts_with(strtolower($t), 'panel_');
        return [
            'tables'   => array_values(array_unique(array_filter($tables, $valid))),
            'prefixes' => array_values(array_unique(array_filter($prefixes, $valid))),
        ];
    }

    /**
     * Config array for `integration_db` built from the settings. Only enabled
     * integrations with a host and database name are included.
     * @return array<string,array<string,mixed>>|null null when settings are unavailable
     */
    public static function configFromSettings(): ?array
    {
        $out = [];
        foreach (array_keys(self::KEYS) as $k) {
            $enabled = Settings::get("extdb_{$k}_enabled", null);
            if ($enabled === null) {
                return null;
            }
            if ((string) $enabled !== '1') {
                continue;
            }
            $host = trim((string) Settings::get("extdb_{$k}_host", ''));
            $name = trim((string) Settings::get("extdb_{$k}_name", ''));
            if ($host === '' || $name === '') {
                continue;
            }
            $owned = self::ownedTables($k);
            if (!$owned['tables'] && !$owned['prefixes']) {
                continue;
            }
            $out[$k] = [
                'host'     => $host,
                'port'     => max(1, min(65535, (int) Settings::get("extdb_{$k}_port", '3306') ?: 3306)),
                'name'     => $name,
                'user'     => (string) Settings::get("extdb_{$k}_user", ''),
                'password' => (string) Settings::get("extdb_{$k}_pass", ''),
                'charset'  => 'utf8mb4',
                'tables'   => $owned['tables'],
                'prefixes' => $owned['prefixes'],
            ];
        }
        return $out;
    }
}
