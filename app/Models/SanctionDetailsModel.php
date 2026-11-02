<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

final class SanctionDetailsModel
{
    /** Load removal history for the displayed page in one query, not per row. */
    public static function removals(string $kind, array $records): array
    {
        [$table, $link, $parent] = match ($kind) {
            'bans' => ['unbans', 'unban_id', 'ban_id'],
            'mutes' => ['unmutes', 'unmute_id', 'mute_id'],
            default => throw new \InvalidArgumentException('Unsupported sanction history: ' . $kind),
        };
        $ids = [];
        foreach ($records as $record) {
            if (!empty($record[$link])) {
                $ids[(int) $record[$link]] = (int) $record['id'];
            }
        }
        if (!$ids) {
            return [];
        }

        $p = Config::get('sa_prefix', 'sa_');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            "SELECT u.*, a.player_name AS removal_admin_name, a.player_steamid AS removal_admin_steamid
             FROM {$p}{$table} u
             LEFT JOIN {$p}admins a ON a.id = u.admin_id
             WHERE u.id IN ({$placeholders})",
            array_keys($ids)
        );
        $result = [];
        foreach ($rows as $row) {
            $recordId = $ids[(int) $row['id']];
            if ((int) $row[$parent] === $recordId) {
                $result[$recordId] = $row;
            }
        }
        return $result;
    }
}
