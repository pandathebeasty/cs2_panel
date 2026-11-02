<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Users with PANEL access (panel_users table), separate from the game
 * admins in sa_admins. Role: owner / moderator (panel-only permissions).
 * Legacy rows ('root' -> owner, 'admin' -> moderator) are normalized on read.
 */
final class PanelUserModel
{
    /** Normalize any stored/legacy role to the current two-role model. */
    public static function normalizeRole(string $role): string
    {
        if ($role === 'root' || $role === 'owner') {
            return 'owner';
        }
        return 'moderator';
    }

    /**
     * Self-heal the role ENUM so 'owner' is accepted even on installs that
     * haven't run the migration yet (e.g. a manual code upload). Runs at most
     * once per request and is best-effort — a failure never blocks the action.
     */
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            Database::run("ALTER TABLE panel_users MODIFY COLUMN `role` ENUM('owner','moderator','root','admin') NOT NULL DEFAULT 'moderator'");
        } catch (\Throwable $e) {
            // Column already wide enough, or DB user lacks ALTER — ignore.
        }
    }

    public static function all(): array
    {
        $rows = Database::all('SELECT * FROM panel_users ORDER BY FIELD(role, "owner","root","admin","moderator"), name ASC');
        foreach ($rows as &$r) {
            $r['role'] = self::normalizeRole((string) $r['role']);
            $r['can_manage'] = $r['role'] === 'owner' ? 1 : 0;
        }
        return $rows;
    }

    public static function findBySteamId(string $steamId64): ?array
    {
        return Database::one('SELECT * FROM panel_users WHERE steamid64 = ? LIMIT 1', [$steamId64]);
    }

    public static function count(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM panel_users');
    }

    public static function create(string $steamId64, string $name, string $role, ?string $addedBy): void
    {
        self::ensureSchema();
        $role = self::normalizeRole($role);
        Database::run(
            'INSERT INTO panel_users (steamid64, name, role, can_manage, added_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role), can_manage = VALUES(can_manage)',
            [$steamId64, $name, $role, $role === 'owner' ? 1 : 0, $addedBy]
        );
    }

    public static function updateRole(int $id, string $role): void
    {
        $role = self::normalizeRole($role);
        Database::run('UPDATE panel_users SET role = ?, can_manage = ? WHERE id = ?', [$role, $role === 'owner' ? 1 : 0, $id]);
    }

    public static function find(int $id): ?array
    {
        $row = Database::one('SELECT * FROM panel_users WHERE id = ? LIMIT 1', [$id]);
        if ($row !== null) {
            $row['role'] = self::normalizeRole((string) $row['role']);
            $row['can_manage'] = $row['role'] === 'owner' ? 1 : 0;
        }
        return $row;
    }

    /**
     * Edit an existing panel user: display name + role. can_manage is derived
     * from the role (owner => full management), there is no separate toggle.
     */
    public static function update(int $id, string $name, string $role): void
    {
        self::ensureSchema();
        $role = self::normalizeRole($role);
        Database::run(
            'UPDATE panel_users SET name = ?, role = ?, can_manage = ? WHERE id = ?',
            [$name !== '' ? $name : 'Owner', $role, $role === 'owner' ? 1 : 0, $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM panel_users WHERE id = ?', [$id]);
    }

    /** Do not allow deleting/demoting the last owner (legacy 'root' counts). */
    public static function ownerCount(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM panel_users WHERE role IN ('owner','root')");
    }

    /** Back-compat alias. */
    public static function rootCount(): int
    {
        return self::ownerCount();
    }
}
