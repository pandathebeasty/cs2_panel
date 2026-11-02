<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Shared PDO connection with prepared-statement reuse.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static bool $failed = false;
    /** @var array<string,PDOStatement> */
    private static array $statements = [];
    private const MAX_CACHED_STATEMENTS = 128;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        // If a previous connect already failed this request, do not retry.
        if (self::$failed) {
            throw new \RuntimeException('Database connection failed.');
        }

        $host    = Config::get('db.host', '127.0.0.1');
        $port    = (int) Config::get('db.port', 3306);
        $name    = Config::get('db.name', '');
        $charset = Config::get('db.charset', 'utf8mb4');

        // In dev preview with no real DB configured, use an in-memory SQLite
        // connection so every query/write succeeds harmlessly (empty data),
        // which makes the whole UI and all actions testable without MySQL.
        if (DevMode::enabled() && (string) $name === '') {
            try {
                self::$pdo = new PDO('sqlite::memory:', null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                return self::$pdo;
            } catch (\Throwable $e) {
                // SQLite driver unavailable: mark failed; dev-guarded helpers return empty.
                self::$failed = true;
                error_log('[CS2Panel] dev SQLite unavailable: ' . $e->getMessage());
                throw new \RuntimeException('Database connection failed.');
            }
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        // In dev preview, fail fast so pages don't hang when no DB is running.
        $connectTimeout = DevMode::enabled() ? 1 : 5;

        try {
            // Persistent connections reuse the TCP+auth handshake across
            // requests handled by the same PHP worker. On a REMOTE MySQL this
            // removes the biggest fixed per-page cost (a fresh connect + auth,
            // + TLS, on every navigation). Enabled by default; set db.persistent
            // to false in config if the host misbehaves with pooled connections.
            $persistent = (bool) Config::get('db.persistent', true);
            self::$pdo = new PDO($dsn, Config::get('db.user', ''), Config::get('db.password', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => $persistent,
                PDO::ATTR_TIMEOUT            => $connectTimeout,
            ]);
        } catch (PDOException $e) {
            // Do not expose credentials; log and throw a generic message
            self::$failed = true;
            error_log('[CS2Panel] DB connect failed: ' . $e->getMessage());
            throw new \RuntimeException('Database connection failed.');
        }

        return self::$pdo;
    }

    /** Execute a query and return the statement (for later fetch). */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $trace = ($_GET['diag'] ?? '') === '1' || ($_POST['diag'] ?? '') === '1';
        $started = $trace ? microtime(true) : 0.0;
        try {
            if (isset(self::$statements[$sql])) {
                $stmt = self::$statements[$sql];
                $stmt->closeCursor();
            } else {
                $stmt = self::connection()->prepare($sql);
                if (count(self::$statements) < self::MAX_CACHED_STATEMENTS) {
                    self::$statements[$sql] = $stmt;
                }
            }
            $stmt->execute($params);
            self::traceQuery($sql, $started);
            return $stmt;
        } catch (\Throwable $e) {
            unset(self::$statements[$sql]);
            self::traceQuery($sql, $started, $e->getMessage());
            if (DevMode::enabled()) {
                // Return a harmless empty statement so write actions "succeed" in dev.
                return self::connection()->query('SELECT 1 WHERE 1=0');
            }
            throw $e;
        }
    }

    /** Log SQL timing only for an explicitly enabled diagnostic session. */
    private static function traceQuery(string $sql, float $started, string $error = ''): void
    {
        if ($started <= 0) {
            return;
        }
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $statement = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        $line = '[' . date('Y-m-d H:i:s') . '] TRACE SQL +' . $elapsed . 'ms ' . substr($statement, 0, 220);
        if ($error !== '') {
            $line .= ' ERROR ' . substr(str_replace(["\r", "\n"], ' ', $error), 0, 240);
        }
        @file_put_contents(BASE_PATH . '/error.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

                           
    public static function all(string $sql, array $params = []): array
    {
        try {
            return self::run($sql, $params)->fetchAll();
        } catch (\Throwable $e) {
            if (DevMode::enabled()) {
                return [];
            }
            throw $e;
        }
    }

    /** Return a single row (or null). */
    public static function one(string $sql, array $params = []): ?array
    {
        try {
            $row = self::run($sql, $params)->fetch();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            if (DevMode::enabled()) {
                return null;
            }
            throw $e;
        }
    }

                                        
    public static function scalar(string $sql, array $params = []): mixed
    {
        try {
            return self::run($sql, $params)->fetchColumn();
        } catch (\Throwable $e) {
            if (DevMode::enabled()) {
                return 0;
            }
            throw $e;
        }
    }

    /** Last insert id. */
    public static function lastInsertId(): string
    {
        return self::connection()->lastInsertId();
    }

    /**
     * Run related writes atomically. A call inside an existing transaction joins
     * it so the outer operation keeps control of commit/rollback.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            return $callback();
        }
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Check whether a table exists (for optional Zenith/VIPCore integrations).
     * Result memoized for the duration of the request.
     */
    public static function tableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        try {
            $exists = (bool) self::run(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            )->fetchColumn();
        } catch (\Throwable $e) {
            $exists = false;
        }
        return $cache[$table] = $exists;
    }

    /** True when a column exists on a table (cached). Safe on missing tables. */
    public static function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $exists = (bool) self::run(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            )->fetchColumn();
        } catch (\Throwable $e) {
            $exists = false;
        }
        return $cache[$key] = $exists;
    }
}
