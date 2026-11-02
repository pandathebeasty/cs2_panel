<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

/**
 * SimpleAdmin admin groups: sa_groups + sa_groups_flags + sa_groups_servers.
 */
final class GroupModel
{
    private static function p(): string
    {
        return Config::get('sa_prefix', 'sa_');
    }
    
    /**
     * Group names excluded from the admin-group UI (configured in Settings).
     * Useful because VIPCore stores its own groups in the same table.
     *
     * @return string[] lower-cased names
     */
    public static function ignored(): array
    {
        $raw = (string) \App\Core\Settings::get('admin_ignored_groups', '');
        $list = preg_split('/[\r\n,]+/', $raw) ?: [];
        $list = array_filter(array_map(static fn($g) => mb_strtolower(trim($g)), $list), static fn($g) => $g !== '');
        return array_values(array_unique($list));
    }

    public static function isIgnored(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), self::ignored(), true);
    }

    /**
     * Human label for a raw SimpleAdmin group name: "#css/owner" -> "Owner",
     * "#vip_gold" -> "Vip Gold". The raw value stays untouched in the DB.
     */
    public static function displayName(string $name): string
    {
        $label = ltrim(trim($name), '#@');
        if (str_contains($label, '/')) {
            $label = substr($label, strrpos($label, '/') + 1);
        }
        $label = trim(str_replace(['_', '-', '.'], ' ', $label));
        if ($label === '') {
            return $name;
        }
        return function_exists('mb_convert_case')
            ? mb_convert_case($label, MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower($label));
    }

    /**
     * Lightweight list of groups as {id, name} in a SINGLE query. Use this
     * where only the id/name are needed (dropdowns, id->name maps) instead of
     * all(), which runs 3 extra queries per group (flags/admin count/servers).
     * @return array<int,array{id:int,name:string,label:string}>
     */
    public static function options(bool $includeIgnored = false): array
    {
        $p = self::p();
        try {
            $rows = Database::all("SELECT id, name FROM {$p}groups ORDER BY immunity DESC, name ASC");
        } catch (\Throwable $e) {
            return [];
        }
        if (!$includeIgnored) {
            $rows = array_filter($rows, static fn($g) => !self::isIgnored((string) $g['name']));
        }
        return array_values(array_map(static fn($g) => [
            'id'    => (int) $g['id'],
            'name'  => (string) $g['name'],
            'label' => self::displayName((string) $g['name']),
        ], $rows));
    }

    /** All groups with the admin count and flags. */
    public static function all(bool $includeIgnored = false): array
    {
        $p = self::p();
        $groups = Database::all("SELECT * FROM {$p}groups ORDER BY immunity DESC, name ASC");
        if (!$includeIgnored) {
            $groups = array_values(array_filter($groups, static fn($g) => !self::isIgnored((string) $g['name'])));
        }
        if (!$groups) {
            return [];
        }

        $ids = array_map(static fn($g) => (int) $g['id'], $groups);
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $flagsByGroup = [];
        foreach (Database::all("SELECT group_id, flag FROM {$p}groups_flags WHERE group_id IN ({$ph})", $ids) as $row) {
            $flagsByGroup[(int) $row['group_id']][] = (string) $row['flag'];
        }

        $countsByGroup = [];
        foreach (Database::all("SELECT group_id, COUNT(*) AS total FROM {$p}admins WHERE group_id IN ({$ph}) GROUP BY group_id", $ids) as $row) {
            $countsByGroup[(int) $row['group_id']] = (int) $row['total'];
        }

        $serversByGroup = [];
        foreach (Database::all("SELECT group_id, server_id FROM {$p}groups_servers WHERE group_id IN ({$ph})", $ids) as $row) {
            $serversByGroup[(int) $row['group_id']][] = (int) $row['server_id'];
        }

        foreach ($groups as &$g) {
            $id = (int) $g['id'];
            $g['label'] = self::displayName((string) $g['name']);
            $g['flags'] = $flagsByGroup[$id] ?? [];
            $g['admin_count'] = $countsByGroup[$id] ?? 0;
            $g['servers'] = $serversByGroup[$id] ?? [];
        }
        unset($g);
        return $groups;
    }

    /** @return string[] */
    public static function flags(int $groupId): array
    {
        $p = self::p();
        $rows = Database::all("SELECT flag FROM {$p}groups_flags WHERE group_id = ?", [$groupId]);
        return array_column($rows, 'flag');
    }

    /** @return int[] */
    public static function serverIds(int $groupId): array
    {
        $p = self::p();
        $rows = Database::all("SELECT server_id FROM {$p}groups_servers WHERE group_id = ?", [$groupId]);
        return array_map('intval', array_column($rows, 'server_id'));
    }

    public static function create(string $name, int $immunity, array $flags, array $serverIds): int
    {
        $p = self::p();
        Database::run("INSERT INTO {$p}groups (name, immunity) VALUES (?, ?)", [$name, $immunity]);
        $id = (int) Database::lastInsertId();
        self::syncFlags($id, $flags);
        self::syncServers($id, $serverIds);
        return $id;
    }

    /**
     * A single group's current state (name, immunity, flags, servers) or null.
     * Used to diff before/after on edit for the audit log.
     *
     * @return array{id:int,name:string,immunity:int,flags:string[],servers:int[]}|null
     */
    public static function find(int $id): ?array
    {
        $p = self::p();
        $row = Database::one("SELECT id, name, immunity FROM {$p}groups WHERE id = ? LIMIT 1", [$id]);
        if ($row === null) {
            return null;
        }
        return [
            'id'       => (int) $row['id'],
            'name'     => (string) $row['name'],
            'immunity' => (int) $row['immunity'],
            'flags'    => self::flags($id),
            'servers'  => self::servers($id),
        ];
    }

    public static function update(int $id, string $name, int $immunity, array $flags, array $serverIds): void
    {
        $p = self::p();
        Database::run("UPDATE {$p}groups SET name = ?, immunity = ? WHERE id = ?", [$name, $immunity, $id]);
        self::syncFlags($id, $flags);
        self::syncServers($id, $serverIds);
    }

    public static function delete(int $id): void
    {
        $p = self::p();
        // FKs have ON DELETE CASCADE for flags/servers; admins in this group -> group_id NULL
        Database::run("DELETE FROM {$p}groups WHERE id = ?", [$id]);
    }

    private static function syncFlags(int $id, array $flags): void
    {
        $p = self::p();
        $panelFlags = array_values(array_filter(self::flags($id), [\App\Core\Flags::class, 'isPanelFlag']));
        $flags = array_merge($flags, $panelFlags);
        Database::run("DELETE FROM {$p}groups_flags WHERE group_id = ?", [$id]);
        $seen = [];
        foreach ($flags as $flag) {
            $flag = is_string($flag) ? trim($flag) : '';
            // Any CounterStrikeSharp permission (@namespace/permission) is valid,
            // not just @css/* — this covers @zenith/*, @k4/*, and custom flags.
            if (self::isValidFlag($flag) && !isset($seen[$flag])) {
                $seen[$flag] = true;
                Database::run("INSERT INTO {$p}groups_flags (group_id, flag) VALUES (?, ?)", [$id, $flag]);
            }
        }
        \App\Core\Flags::remember(array_keys($seen));
    }

    /** A valid flag starts with '@', has no whitespace, and is reasonably short. */
    private static function isValidFlag(string $flag): bool
    {
        return \App\Core\Flags::isValid($flag);
    }

    private static function syncServers(int $id, array $serverIds): void
    {
        $p = self::p();
        Database::run("DELETE FROM {$p}groups_servers WHERE group_id = ?", [$id]);
        foreach ($serverIds as $sid) {
            $sid = (int) $sid;
            if ($sid > 0) {
                Database::run("INSERT INTO {$p}groups_servers (group_id, server_id) VALUES (?, ?)", [$id, $sid]);
            }
        }
    }
}
