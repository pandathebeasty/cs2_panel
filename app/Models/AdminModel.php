<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

/**
 * Acces la datele adminilor din SimpleAdmin (sa_admins + sa_admins_flags + sa_groups).
 */
final class AdminModel
{
    private static function p(): string
    {
        return Config::get('sa_prefix', 'sa_');
    }

    /** The admin by steamid64, with the associated group. */
    public static function findBySteamId(string $steamId64): ?array
    {
        $p = self::p();
        return Database::one(
            "SELECT a.*, g.name AS group_name, g.immunity AS group_immunity
             FROM {$p}admins a
             LEFT JOIN {$p}groups g ON g.id = a.group_id
             WHERE a.player_steamid = ?
             LIMIT 1",
            [$steamId64]
        );
    }

    /**
     * All effective flags of an admin:
     *  - direct flags from sa_admins_flags
     *  - group flags (sa_groups_flags)
     *  - fallback pe coloana text sa_admins.flags (format vechi, separate prin virgula/spatiu)
     * @return string[]
     */
    public static function effectiveFlags(array $admin): array
    {
        $p = self::p();
        $flags = [];

        // direct flags
        $rows = Database::all(
            "SELECT flag FROM {$p}admins_flags WHERE admin_id = ?",
            [$admin['id']]
        );
        foreach ($rows as $r) {
            $flags[] = $r['flag'];
        }

        // group flags
        if (!empty($admin['group_id'])) {
            $rows = Database::all(
                "SELECT flag FROM {$p}groups_flags WHERE group_id = ?",
                [$admin['group_id']]
            );
            foreach ($rows as $r) {
                $flags[] = $r['flag'];
            }
        }

        // fallback to the text column
        if (!empty($admin['flags'])) {
            foreach (preg_split('/[\s,]+/', (string) $admin['flags']) as $f) {
                if ($f !== '') {
                    $flags[] = $f;
                }
            }
        }

        return array_values(array_unique($flags));
    }
}
