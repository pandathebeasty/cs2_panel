<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\ServerModel;
use App\Models\VipModel;

/**
 * Best-effort live refresh: after a VIP/admin change lands in the database
 * (contest grant, manual add, store purchase), tell every configured game
 * server via RCON to reload from DB, so the player doesn't have to reconnect
 * for it to take effect. Never throws — a refresh failure must never block or
 * roll back the grant that already succeeded; it's just logged.
 */
final class ServerSync
{
    /** Ask every configured server to reload VIP data from the database. */
    public static function refreshVip(): void
    {
        if (!VipModel::enabled()) {
            return;
        }
        self::broadcast(trim((string) Config::get('rcon.reload_vip_command', 'css_vip_reload')));
    }

    /** Ask every configured server to reload in-game admins from the database. */
    public static function refreshAdmins(): void
    {
        self::broadcast(trim((string) Config::get('rcon.reload_admins_command', 'css_reload_admins')));
    }

    private static function broadcast(string $command): void
    {
        if ($command === '') {
            return; // explicitly disabled via config
        }
        foreach (ServerModel::all() as $srv) {
            $password = (string) ($srv['rcon_password'] ?? '');
            $address  = (string) ($srv['address'] ?? '');
            if ($password === '' || $address === '') {
                continue;
            }
            $parts = explode(':', $address, 2);
            $host  = trim($parts[0]);
            $port  = isset($parts[1]) && ctype_digit(trim($parts[1])) ? (int) trim($parts[1]) : 27015;
            if ($host === '') {
                continue;
            }
            try {
                $rcon = new Rcon($host, $port, $password, (int) Config::get('rcon.timeout', 3));
                $rcon->connect();
                try {
                    $rcon->command($command);
                } finally {
                    $rcon->disconnect();
                }
            } catch (\Throwable $e) {
                error_log('[CS2Panel] server refresh (' . $command . ') failed for ' . $host . ':' . $port . ': ' . $e->getMessage());
            }
        }
    }
}
