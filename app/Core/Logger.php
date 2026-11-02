<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Panel action log (panel_logs table).
 * Non-fatal: if the write fails, the action is not blocked.
 */
final class Logger
{
    /**
     * Create the panel_logs table if it's missing. The installer ships it in
     * panel_schema.sql, but a hand-uploaded panel may never have run the
     * installer, so Schema::sync() also calls this to self-heal. Runs at most
     * once per request; never throws.
     */
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            Database::run(
                'CREATE TABLE IF NOT EXISTS `panel_logs` (
                    `id`         INT NOT NULL AUTO_INCREMENT,
                    `actor_steamid` VARCHAR(32) NULL,
                    `actor_name` VARCHAR(128) NULL,
                    `action`     VARCHAR(64) NOT NULL,
                    `target`     VARCHAR(191) NULL,
                    `details`    VARCHAR(255) NULL,
                    `ip`         VARCHAR(45) NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_created` (`created_at`),
                    KEY `idx_action` (`action`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] Logger ensureTable failed: ' . $e->getMessage());
        }
    }

    /**
     * @param string               $action  ex: 'ban.create', 'vip.remove', 'settings.save'
     * @param string|int|null      $target  ex: SteamID64 or id (BIGINT columns
     *                                       arrive as int from PDO, so we accept
     *                                       both and normalize to string)
     * @param string|int|null      $details short text
     */
    public static function log(string $action, string|int|null $target = null, string|int|null $details = null): void
    {
        // Write to the DB action log when the table exists. This is independent
        // of the Discord mirror below — a missing/unreadable log table must NOT
        // stop the Discord notification (previously an early return here killed
        // it, so notifications silently stopped whenever logging was unavailable).
        try {
            if (Database::tableExists('panel_logs')) {
                Database::run(
                    'INSERT INTO panel_logs (actor_steamid, actor_name, action, target, details, ip)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        Auth::steamId(),
                        Auth::user()['name'] ?? null,
                        $action,
                        $target === null ? null : (string) $target,
                        $details === null ? null : (string) $details,
                        self::clientIp(),
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] Logger error: ' . $e->getMessage());
        }

        // Discord::audit() skips actions with their own detailed notifications.
        // Notification failures must not undo the recorded action.
                                                                     
        try {
            Discord::audit($action, $target === null ? null : (string) $target, $details === null ? null : (string) $details);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] Logger Discord audit error: ' . $e->getMessage());
        }
    }

    public static function actionLabel(string $action): string
    {
        $key = 'log.act.' . $action;
        $label = Lang::t($key);
        return $label === $key ? ucfirst(str_replace(['.', '_'], ' ', $action)) : $label;
    }

    public static function clientIp(): string
    {
        // in spatele unui proxy, X-Forwarded-For; altfel REMOTE_ADDR
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            $parts = explode(',', $fwd);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function paginate(array $filters, int $page, int $perPage): Paginator
    {
        $where = [];
        $params = [];
        if (!empty($filters['search'])) {
            $steamId = Steam::toSteamId64Any((string) $filters['search']);
            $where[] = '(actor_name LIKE ? OR actor_steamid LIKE ? OR target LIKE ? OR action LIKE ?'
                . ($steamId !== null ? ' OR actor_steamid = ? OR target = ?)' : ')');
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
            if ($steamId !== null) array_push($params, $steamId, $steamId);
        }
        if (!empty($filters['action'])) {
            $where[] = 'action = ?';
            $params[] = $filters['action'];
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        return Paginator::make(
            "SELECT * FROM panel_logs {$whereSql}",
            "SELECT COUNT(*) FROM panel_logs {$whereSql}",
            $params,
            $page,
            $perPage,
            'id DESC'
        );
    }

                                                                  
    public static function clearAll(): int
    {
        if (!Database::tableExists('panel_logs')) {
            return 0;
        }
        $count = (int) Database::scalar('SELECT COUNT(*) FROM panel_logs');
        Database::run('DELETE FROM panel_logs');
        return $count;
    }

    /** Distinct actions for the filter. */
    public static function actions(): array
    {
        if (!Database::tableExists('panel_logs')) {
            return [];
        }
        $rows = Database::all('SELECT DISTINCT action FROM panel_logs ORDER BY action ASC');
        return array_column($rows, 'action');
    }

    /**
     * Best-effort SteamID64 => player name map, looked up across the tables the
     * panel already owns (admins, bans, mutes, warns and the panel's own log).
     * Used so the action log can name people instead of printing bare ids.
     *
     * @param string[] $steamIds
     * @return array<string,string>
     */
    public static function resolveNames(array $steamIds): array
    {
        $steamIds = array_values(array_unique(array_filter($steamIds)));
        if (!$steamIds) {
            return [];
        }
        $p  = Config::get('sa_prefix', 'sa_');
        $ph = implode(',', array_fill(0, count($steamIds), '?'));
        $queries = [
            "SELECT player_steamid AS sid, player_name AS name FROM {$p}admins WHERE player_steamid IN ({$ph})",
            "SELECT player_steamid AS sid, player_name AS name FROM {$p}bans WHERE player_steamid IN ({$ph})",
            "SELECT player_steamid AS sid, player_name AS name FROM {$p}mutes WHERE player_steamid IN ({$ph})",
            "SELECT player_steamid AS sid, player_name AS name FROM {$p}warns WHERE player_steamid IN ({$ph})",
            "SELECT actor_steamid AS sid, actor_name AS name FROM panel_logs WHERE actor_steamid IN ({$ph})",
        ];

        $out = [];
        foreach ($queries as $sql) {
            try {
                foreach (Database::all($sql, $steamIds) as $row) {
                    $sid  = (string) $row['sid'];
                    $name = trim((string) $row['name']);
                    if ($name !== '' && !isset($out[$sid])) {
                        $out[$sid] = $name;
                    }
                }
            } catch (\Throwable $e) {
                // Table missing on this install — just skip that source.
            }
        }
        return $out;
    }
}
