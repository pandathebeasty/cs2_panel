<?php

declare(strict_types=1);

namespace App\Core;

/**
 * TEMPORARY development preview.
 *
 * When enabled, the panel can be browsed WITHOUT running the installer:
 *  - the /install redirect is skipped,
 *  - a fake "root" session is injected so authenticated pages render,
 *  - minimal config defaults are provided.
 *
 * Enable it by creating an empty file at .dev/DEV_MODE (git-ignored) OR by
 * setting the env var CS2PANEL_DEV=1. This must never be used in production;
 * DB-backed lists will simply appear empty if no database is configured.
 */
final class DevMode
{
    public static function enabled(): bool
    {
        if (getenv('CS2PANEL_DEV') === '1') {
            return true;
        }
        return is_file(BASE_PATH . '/.dev/DEV_MODE');
    }

    /** Provide minimal config so the app can boot without config/config.php. */
    public static function seedConfig(): void
    {
        Config::init([
            'db'        => ['host' => '127.0.0.1', 'port' => 3306, 'name' => '', 'user' => '', 'password' => '', 'charset' => 'utf8mb4'],
            'sa_prefix' => 'sa_',
            'zenith'    => ['enabled' => true, 'storage_table' => 'zenith_player_storage', 'stats_table' => 'zenith_stats', 'ranks_table' => 'zenith_ranks', 'steam_column' => 'steam_id'],
            'vipcore'   => ['enabled' => true, 'users_table' => 'vip_users', 'servers_table' => 'vip_servers'],
            'steam'     => ['api_key' => '', 'realm' => 'http://localhost', 'return_url' => 'http://localhost/auth/steam/return'],
            'super_admins' => [],
            'app'       => ['name' => 'CS2 Panel (DEV)', 'timezone' => 'UTC', 'debug' => true, 'per_page' => 25],
            'rcon'      => ['timeout' => 3],
        ]);
    }

    /** Inject a fake root session so protected pages are viewable. */
    public static function fakeLogin(): void
    {
        if (!Auth::check()) {
            $_SESSION['user'] = [
                'steamid'    => '76561197960287930',
                'name'       => 'Dev Preview',
                'avatar'     => Steam::avatarPlaceholder(),
                'flags'      => ['@css/root'],
                'panel_role' => 'root',
                'can_manage' => true,
                'immunity'   => 100,
            ];
        }
    }
}
