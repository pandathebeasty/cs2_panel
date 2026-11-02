<?php
/**
 * Copy this file to config/config.php and fill in the values.
 * config/config.php is gitignored. Normally the setup wizard writes it for you.
 */

return [
    // SimpleAdmin database connection (the same DB the plugin uses).
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'cs2_simpleadmin',
        'user'     => 'paneluser',
        'password' => 'change_me',
        'charset'  => 'utf8mb4',
    ],

    // SimpleAdmin table prefix (default sa_).
    'sa_prefix' => 'sa_',

                                    
    // Zenith stores data in configurable tables. Fill in the real names from
    // your DB (check with SHOW TABLES LIKE 'zenith%').
    'zenith' => [
        'enabled'        => true,
        // main storage table (steam_id, name, last_online ...)
        'storage_table'  => 'zenith_player_storage',
        // stats table (kills, deaths, etc.)
        'stats_table'    => 'zenith_stats',
        // ranks / points table
        'ranks_table'    => 'zenith_ranks',
        // the steamid64 column in the zenith tables
        'steam_column'   => 'steam_id',
    ],

                                                             
    // The vip_users and vip_servers tables are created by the plugin (same DB or another).
    // NOTE: vip_users uses `account_id` = SteamID64 - 76561197960265728 (32-bit AccountID),
    // NOT the full SteamID64 like SimpleAdmin. The panel converts automatically.
    'vipcore' => [
        'enabled'       => true,
        'users_table'   => 'vip_users',
        'servers_table' => 'vip_servers',
    ],

    // Steam OpenID authentication.
    'steam' => [
        'api_key'      => '',                 // https://steamcommunity.com/dev/apikey
        'realm'        => 'http://localhost', // your domain (no trailing slash)
        'return_url'   => 'http://localhost/auth/steam/return',
    ],

    // SteamID64s with full access regardless of flags (bootstrap admins).
    'super_admins' => [
        // '76561198000000000',
    ],

    'app' => [
        'name'     => 'CS2 Panel',
        'timezone' => 'UTC',
        'debug'    => false,       // true only in development
        'per_page' => 25,          // default pagination size
    ],

    // RCON: short timeout so a request never hangs (avoids 500/hang).
    'rcon' => [
        'timeout' => 3, // seconds
        // Best-effort live refresh after a VIP/admin change (grant, extend,
        // contest award, store purchase) so players don't need to reconnect.
                                                                             
        // console commands if you're on a different CS2-SimpleAdmin/VIPCore build.
        'reload_vip_command'    => 'css_vip_reload',
        'reload_admins_command' => 'css_reload_admins',
    ],

    // A2S server query (server overview: live map/players/status). Short timeout
    // so an unreachable server just shows as offline instead of stalling the page.
    'query' => [
        'timeout' => 1.0, // seconds
    ],
];
