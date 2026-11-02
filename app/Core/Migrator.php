<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Tiny SQL migration runner.
 *
 * Migrations are plain .sql files in database/migrations/, named with a numeric
 * index so they sort in order, e.g. "001_add_vip_groups.sql". Each applied file is recorded
 * in the panel_migrations table so it runs exactly once. Safe to call on every
 * upgrade — only files not yet recorded are executed.
 */
final class Migrator
{
    /** Default migrations directory shipped with the panel. */
    public static function dir(): string
    {
        return BASE_PATH . '/database/migrations';
    }

    /** Ensure the tracking table exists. */
    private static function ensureTable(?\PDO $pdo = null): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `panel_migrations` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `filename` VARCHAR(191) NOT NULL,
                `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_filename` (`filename`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        if ($pdo !== null) {
            $pdo->exec($sql);
            return;
        }
        Database::run($sql);
    }

    /** @return string[] filenames already applied */
    private static function applied(?\PDO $pdo = null): array
    {
        try {
            if ($pdo !== null) {
                return array_column($pdo->query('SELECT filename FROM panel_migrations')->fetchAll(\PDO::FETCH_ASSOC), 'filename');
            }
            $rows = Database::all('SELECT filename FROM panel_migrations');
            return array_column($rows, 'filename');
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Run every migration in $dir (defaults to the bundled folder) that hasn't
     * been applied yet, in filename order. Pass $pdo to run during install,
     * before config.php exists and Database can connect.
     * @return string[] the filenames that were applied this run
     */
    public static function runPending(?string $dir = null, ?\PDO $pdo = null): array
    {
        // No DB in dev preview -> nothing to migrate.
        if ($pdo === null && DevMode::enabled() && (string) Config::get('db.name', '') === '') {
            return [];
        }
        $dir = $dir ?? self::dir();
        if (!is_dir($dir)) {
            return [];
        }

        self::ensureTable($pdo);
        $done = self::applied($pdo);

        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $ran = [];
        foreach ($files as $path) {
            $name = basename($path);
            if (in_array($name, $done, true)) {
                continue;
            }
            $sql = (string) file_get_contents($path);
            if (trim($sql) === '') {
                self::record($name, $pdo);
                continue;
            }
            try {
                self::execScript($sql, $pdo);
                $ran[] = $name;
                self::record($name, $pdo);
            } catch (\Throwable $e) {
                self::logFailure($name, $e);
            }
        }
        return $ran;
    }

    /** @return string[] migration filenames not yet recorded as applied */
    public static function pending(?string $dir = null): array
    {
        $dir = $dir ?? self::dir();
        $files = array_map('basename', glob($dir . '/*.sql') ?: []);
        try {
            $done = self::applied();
        } catch (\Throwable $e) {
            return $files;
        }
        return array_values(array_diff($files, $done));
    }

    private static function logFailure(string $name, \Throwable $e): void
    {
        $message = 'Migration ' . $name . ' failed: ' . $e->getMessage();
        error_log('[CS2Panel] ' . $message);
        @file_put_contents(
            BASE_PATH . '/error.log',
            '[' . date('Y-m-d H:i:s') . '] [CS2Panel] ' . $message . "\n\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * MySQL errors that mean "this change is already in place". Panels upgraded
     * by hand often already have the tables/columns (the models create them
     * lazily), so re-running a migration must not be treated as a failure.
     */
    private const IDEMPOTENT_ERRORS = [
        '1007', // database exists
        '1050', // table already exists
        '1060', // duplicate column name
        '1061', // duplicate key name
        '1091', // can't DROP; check that column/key exists
        '1826', // duplicate foreign key constraint name
    ];

    /** Execute a multi-statement SQL script, statement by statement. */
    private static function execScript(string $sql, ?\PDO $pdo = null): void
    {
        $pdo = $pdo ?? Database::connection();
                                                                                 
        // This splitter supports panel migrations, not stored routines.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = array_filter(
            array_map('trim', explode(";\n", str_replace(";\r\n", ";\n", $sql) . "\n")),
            static fn($s) => $s !== '' && $s !== ';'
        );
        foreach ($statements as $stmt) {
            $stmt = rtrim($stmt, "; \t\r\n");
            if ($stmt === '') {
                continue;
            }
            try {
                $pdo->exec($stmt);
            } catch (\PDOException $e) {
                if (!in_array((string) ($e->errorInfo[1] ?? ''), self::IDEMPOTENT_ERRORS, true)) {
                    throw $e;
                }
            }
        }
    }

    private static function record(string $filename, ?\PDO $pdo = null): void
    {
        $sql = 'INSERT INTO panel_migrations (filename) VALUES (?)
             ON DUPLICATE KEY UPDATE filename = filename';
        if ($pdo !== null) {
            $pdo->prepare($sql)->execute([$filename]);
            return;
        }
        Database::run($sql, [$filename]);
    }
}
