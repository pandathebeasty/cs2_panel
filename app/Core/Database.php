<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Shared PDO connection with prepared-statement reuse.
 *
 * Plugin integrations (SimpleAdmin, VIPCore, Zenith, ...) may live in their own
 * database: config `integration_db.<key>` (written from Settings > Integrations)
 * holds the credentials plus the tables/prefixes owned by that integration.
 * Every query is routed to the connection that owns the tables it mentions, so
 * models keep calling Database::run()/all()/... unchanged.
 */
final class Database
{
    public const DEFAULT = 'default';

    private static ?PDO $pdo = null;
    private static bool $failed = false;
    /** @var array<string,PDO> separate integration connections */
    private static array $extra = [];
    /** @var array<string,true> integrations whose connection already failed */
    private static array $extraFailed = [];
    /** Connection used by the latest query (for lastInsertId). */
    private static string $lastKey = self::DEFAULT;
    /** @var array<string,PDOStatement> */
    private static array $statements = [];
    private const MAX_CACHED_STATEMENTS = 128;

    /** @var array{regex:string, tables:array<string,string>, prefixes:array<string,string>}|null|false */
    private static array|null|false $routes = null;
    /** @var array<string,string> sql => connection key */
    private static array $routeCache = [];

    /**
     * Valid separate-database configs keyed by integration.
     * @return array<string,array<string,mixed>>
     */
    public static function separateConfigs(): array
    {
        $out = [];
        $all = Config::get('integration_db', []);
        if (!is_array($all)) {
            return [];
        }
        foreach ($all as $key => $cfg) {
            if (is_string($key) && $key !== self::DEFAULT && is_array($cfg)
                && trim((string) ($cfg['name'] ?? '')) !== '' && trim((string) ($cfg['host'] ?? '')) !== '') {
                $out[$key] = $cfg;
            }
        }
        return $out;
    }

    /** True when the integration uses its own database. */
    public static function isSeparate(string $key): bool
    {
        return isset(self::separateConfigs()[$key]);
    }

    /**
     * PDO for the panel ('default') or for an integration. An integration
     * without its own database shares the default connection.
     */
    public static function connection(string $key = self::DEFAULT): PDO
    {
        if ($key !== self::DEFAULT) {
            $cfg = self::separateConfigs()[$key] ?? null;
            if ($cfg !== null) {
                return self::extraConnection($key, $cfg);
            }
        }
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

    /** @param array<string,mixed> $cfg */
    private static function extraConnection(string $key, array $cfg): PDO
    {
        if (isset(self::$extra[$key])) {
            return self::$extra[$key];
        }
        if (isset(self::$extraFailed[$key])) {
            throw new \RuntimeException('Database connection failed (' . $key . ').');
        }
        try {
            self::$extra[$key] = self::open($cfg, DevMode::enabled() ? 1 : 5, (bool) Config::get('db.persistent', true));
        } catch (PDOException $e) {
            self::$extraFailed[$key] = true;
            error_log('[CS2Panel] DB connect failed for ' . $key . ': ' . $e->getMessage());
            throw new \RuntimeException('Database connection failed (' . $key . ').');
        }
        return self::$extra[$key];
    }

    /**
     * Open a MySQL connection from host/port/name/user/password/charset.
     * @param array<string,mixed> $cfg
     */
    public static function open(array $cfg, int $timeout = 5, bool $persistent = false): PDO
    {
        $host    = trim((string) ($cfg['host'] ?? '127.0.0.1'));
        $port    = (int) ($cfg['port'] ?? 3306) ?: 3306;
        $name    = trim((string) ($cfg['name'] ?? ''));
        $charset = preg_match('/^[A-Za-z0-9_]+$/', (string) ($cfg['charset'] ?? '')) ? (string) $cfg['charset'] : 'utf8mb4';
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        return new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['password'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => $persistent,
            PDO::ATTR_TIMEOUT            => $timeout,
        ]);
    }

    /**
     * Try a connection without keeping it. Reports which of the expected
     * tables exist in that database.
     * @param array<string,mixed> $cfg
     * @param string[] $tables
     * @return array{ok:bool, error?:string, server?:string, found?:string[], missing?:string[], xa?:bool}
     */
    public static function test(array $cfg, array $tables = []): array
    {
        try {
            $pdo = self::open($cfg, 5, false);
            $server = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $found = [];
            $missing = [];
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            foreach (array_unique(array_filter($tables)) as $t) {
                $stmt->execute([$t]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $found[] = $t;
                } else {
                    $missing[] = $t;
                }
            }
            return ['ok' => true, 'server' => $server, 'found' => $found, 'missing' => $missing, 'xa' => self::supportsXa($pdo)];
        } catch (\Throwable $e) {
            // Keep the driver message (host/user errors) but never the password.
            $msg = str_replace((string) ($cfg['password'] ?? "\0"), '***', $e->getMessage());
            return ['ok' => false, 'error' => $msg];
        }
    }

    /**
     * Which connection owns the tables used by a query. Without separate
     * databases configured this is always 'default' (no parsing at all).
     */
    public static function routeFor(string $sql, array $params = []): string
    {
        if (self::$routes === null) {
            self::$routes = self::buildRoutes();
        }
        if (self::$routes === false) {
            return self::DEFAULT;
        }
        $schemaQuery = stripos($sql, 'information_schema') !== false;
        if (!$schemaQuery && isset(self::$routeCache[$sql])) {
            return self::$routeCache[$sql];
        }
        // Ignore string literals so values never decide the route.
        $code = preg_replace(["/'(?:[^'\\\\]|\\\\.|'')*'/s", '/"(?:[^"\\\\]|\\\\.|"")*"/s'], "''", $sql) ?? $sql;
        $key = self::matchRoute($code);
        if ($key === null && $schemaQuery) {
            // information_schema lookups pass the table name as a parameter.
            foreach ($params as $p) {
                if (is_string($p) && ($key = self::matchRoute($p)) !== null) {
                    break;
                }
            }
        }
        $key ??= self::DEFAULT;
        if (!$schemaQuery && count(self::$routeCache) < 512) {
            self::$routeCache[$sql] = $key;
        }
        return $key;
    }

    private static function matchRoute(string $text): ?string
    {
        $r = self::$routes;
        if (!is_array($r) || !preg_match_all($r['regex'], $text, $m)) {
            return null;
        }
        $hit = null;
        foreach ($m[1] as $name) {
            $lower = strtolower($name);
            $key = $r['tables'][$lower] ?? null;
            if ($key === null) {
                foreach ($r['prefixes'] as $prefix => $pkey) {
                    if (str_starts_with($lower, $prefix)) {
                        $key = $pkey;
                        break;
                    }
                }
            }
            if ($key === null) {
                continue;
            }
            if ($hit !== null && $hit !== $key) {
                error_log('[CS2Panel] Query mixes tables from separate databases (' . $hit . ', ' . $key . '); using ' . $hit . '.');
                break;
            }
            $hit = $key;
        }
        return $hit;
    }

    /** @return array{regex:string, tables:array<string,string>, prefixes:array<string,string>}|false */
    private static function buildRoutes(): array|false
    {
        $tables = [];
        $prefixes = [];
        foreach (self::separateConfigs() as $key => $cfg) {
            foreach ((array) ($cfg['tables'] ?? []) as $t) {
                $t = strtolower(trim((string) $t));
                if (preg_match('/^[a-z0-9_$]+$/', $t) && !isset($tables[$t])) {
                    $tables[$t] = $key;
                }
            }
            foreach ((array) ($cfg['prefixes'] ?? []) as $p) {
                $p = strtolower(trim((string) $p));
                // A prefix must be specific enough to never catch panel_* tables.
                if (strlen($p) >= 2 && preg_match('/^[a-z0-9_$]+$/', $p) && !str_starts_with('panel_', $p) && !isset($prefixes[$p])) {
                    $prefixes[$p] = $key;
                }
            }
        }
        if (!$tables && !$prefixes) {
            return false;
        }
        // Longest prefix first, so "sa_" never shadows a more specific one.
        uksort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $alts = array_map(static fn(string $t): string => preg_quote($t, '/'), array_keys($tables));
        foreach (array_keys($prefixes) as $p) {
            $alts[] = preg_quote($p, '/') . '[a-z0-9_$]+';
        }
        usort($alts, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return [
            'regex'    => '/(?<![a-z0-9_$])(' . implode('|', $alts) . ')(?![a-z0-9_$])/i',
            'tables'   => $tables,
            'prefixes' => $prefixes,
        ];
    }

    /** Forget cached routing/connections (after the config changed). */
    public static function resetRouting(): void
    {
        self::$routes = null;
        self::$routeCache = [];
        self::$extraFailed = [];
        foreach (array_keys(self::$extra) as $key) {
            foreach (array_keys(self::$statements) as $sk) {
                if (str_starts_with($sk, $key . "\0")) {
                    unset(self::$statements[$sk]);
                }
            }
        }
        self::$extra = [];
    }

    /**
     * Execute a query and return the statement (for later fetch).
     * $key pins an integration connection (e.g. 'zenith'); null routes by the
     * table names. A pinned integration without its own database uses the
     * default connection.
     */
    public static function run(string $sql, array $params = [], ?string $key = null): PDOStatement
    {
        $trace = ($_GET['diag'] ?? '') === '1' || ($_POST['diag'] ?? '') === '1';
        $started = $trace ? microtime(true) : 0.0;
        $key = $key === null
            ? self::routeFor($sql, $params)
            : ($key !== self::DEFAULT && self::isSeparate($key) ? $key : self::DEFAULT);
        $cacheKey = $key === self::DEFAULT ? $sql : $key . "\0" . $sql;
        try {
            if (isset(self::$statements[$cacheKey])) {
                $stmt = self::$statements[$cacheKey];
                $stmt->closeCursor();
            } else {
                $stmt = self::connection($key)->prepare($sql);
                if (count(self::$statements) < self::MAX_CACHED_STATEMENTS) {
                    self::$statements[$cacheKey] = $stmt;
                }
            }
            self::$lastKey = $key;
            $stmt->execute($params);
            self::traceQuery(($key === self::DEFAULT ? '' : '[' . $key . '] ') . $sql, $started);
            return $stmt;
        } catch (\Throwable $e) {
            unset(self::$statements[$cacheKey]);
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

                           
    public static function all(string $sql, array $params = [], ?string $key = null): array
    {
        try {
            return self::run($sql, $params, $key)->fetchAll();
        } catch (\Throwable $e) {
            if (DevMode::enabled()) {
                return [];
            }
            throw $e;
        }
    }

    /** Return a single row (or null). */
    public static function one(string $sql, array $params = [], ?string $key = null): ?array
    {
        try {
            $row = self::run($sql, $params, $key)->fetch();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            if (DevMode::enabled()) {
                return null;
            }
            throw $e;
        }
    }

                                        
    public static function scalar(string $sql, array $params = [], ?string $key = null): mixed
    {
        try {
            return self::run($sql, $params, $key)->fetchColumn();
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
        return self::connection(self::$lastKey)->lastInsertId();
    }

    /**
     * Run related writes atomically. A call inside an existing transaction joins
     * it so the outer operation keeps control of commit/rollback. Pass the
     * integration key when the writes target an integration's tables.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(callable $callback, string $key = self::DEFAULT): mixed
    {
        $pdo = self::connection($key);
        if ($pdo->inTransaction() || isset(self::$xa[spl_object_id($pdo)])) {
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
     * Run writes that span the panel and one or more integrations atomically.
     * When they all share one connection this is a normal transaction. When an
     * integration has its own database, a two-phase commit (MySQL/MariaDB XA)
     * ensures every database commits, or none of them does.
     *
     * @template T
     * @param string[] $keys connection keys involved, e.g. ['default', 'simpleadmin']
     * @param callable(): T $callback
     * @return T
     */
    public static function atomic(array $keys, callable $callback): mixed
    {
        $conns = [];
        foreach (array_unique($keys ?: [self::DEFAULT]) as $key) {
            $pdo = self::connection((string) $key);
            $conns[spl_object_id($pdo)] ??= ['key' => (string) $key, 'pdo' => $pdo];
        }
        if (count($conns) === 1) {
            return self::transaction($callback, reset($conns)['key']);
        }
        foreach ($conns as $id => $c) {
            if ($c['pdo']->inTransaction() || isset(self::$xa[$id])) {
                throw new \LogicException('A cross-database operation cannot run inside another transaction.');
            }
            if ($c['pdo']->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                throw new \RuntimeException('Cross-database writes need MySQL/MariaDB connections.');
            }
        }

        $base = 'cs2p-' . bin2hex(random_bytes(8)) . '-';
        $n = 0;
        self::registerXaGuard();
        try {
            foreach ($conns as $id => $c) {
                $xid = $base . $n++;
                $c['pdo']->exec("XA START '{$xid}'");
                self::$xa[$id] = ['key' => $c['key'], 'pdo' => $c['pdo'], 'xid' => $xid, 'state' => 'active'];
            }
            $result = $callback();
            foreach (self::$xa as $id => $x) {
                $x['pdo']->exec("XA END '{$x['xid']}'");
                self::$xa[$id]['state'] = 'idle';
            }
            foreach (self::$xa as $id => $x) {
                $x['pdo']->exec("XA PREPARE '{$x['xid']}'");
                self::$xa[$id]['state'] = 'prepared';
            }
        } catch (\Throwable $e) {
            self::xaFinish(false);
            throw $e;
        }
        // Every database promised to commit: from here the commit must be completed.
        self::xaFinish(true);
        return $result;
    }

    /**
     * @var array<int, array{key:string, pdo:PDO, xid:string, state:string}>
     * Open two-phase-commit branches, keyed by PDO object id.
     */
    private static array $xa = [];
    private static bool $xaGuard = false;

    /** Roll back (or finish committing) open XA branches if the request dies mid-way. */
    private static function registerXaGuard(): void
    {
        if (self::$xaGuard) {
            return;
        }
        self::$xaGuard = true;
        register_shutdown_function(static function (): void {
            if (self::$xa) {
                $allPrepared = !array_filter(self::$xa, static fn(array $x): bool => $x['state'] !== 'prepared');
                self::xaFinish($allPrepared);
            }
        });
    }

    /**
     * Commit or roll back every open XA branch. A prepared branch whose commit
     * fails (e.g. connection lost) survives on its server; it is retried on a
     * fresh connection and, if still failing, logged with the recovery command.
     */
    private static function xaFinish(bool $commit): void
    {
        $failed = [];
        foreach (self::$xa as $id => $x) {
            $xid = $x['xid'];
            try {
                if ($x['state'] === 'active') {
                    $x['pdo']->exec("XA END '{$xid}'");
                }
                $x['pdo']->exec(($commit ? 'XA COMMIT' : 'XA ROLLBACK') . " '{$xid}'");
            } catch (\Throwable $e) {
                if ($x['state'] !== 'prepared') {
                    // Never prepared: the server discards it when the connection ends.
                    error_log('[CS2Panel] XA rollback on ' . $x['key'] . ' failed: ' . $e->getMessage());
                    self::dropConnection($x['key']);
                    continue;
                }
                try {
                    self::freshConnection($x['key'])->exec(($commit ? 'XA COMMIT' : 'XA ROLLBACK') . " '{$xid}'");
                } catch (\Throwable $retry) {
                    $failed[] = $x['key'] . ": XA " . ($commit ? 'COMMIT' : 'ROLLBACK') . " '{$xid}'";
                    error_log('[CS2Panel] CRITICAL: XA branch ' . $xid . ' on ' . $x['key'] . ' is still prepared ('
                        . $retry->getMessage() . '). Run "XA ' . ($commit ? 'COMMIT' : 'ROLLBACK') . " '{$xid}'\" on that database.");
                }
            }
            unset(self::$xa[$id]);
        }
        self::$xa = [];
        if ($failed) {
            throw new \RuntimeException('Cross-database commit incomplete: ' . implode('; ', $failed));
        }
    }

    /** A new, non-persistent connection for the given key (XA recovery). */
    private static function freshConnection(string $key): PDO
    {
        $cfg = $key === self::DEFAULT ? null : (self::separateConfigs()[$key] ?? null);
        $cfg ??= [
            'host' => Config::get('db.host', '127.0.0.1'), 'port' => Config::get('db.port', 3306),
            'name' => Config::get('db.name', ''), 'user' => Config::get('db.user', ''),
            'password' => Config::get('db.password', ''), 'charset' => Config::get('db.charset', 'utf8mb4'),
        ];
        return self::open($cfg, 5, false);
    }

    /** Forget a broken connection so the next query reconnects. */
    private static function dropConnection(string $key): void
    {
        foreach (array_keys(self::$statements) as $sk) {
            if ($key === self::DEFAULT ? !str_contains($sk, "\0") : str_starts_with($sk, $key . "\0")) {
                unset(self::$statements[$sk]);
            }
        }
        if ($key === self::DEFAULT || !isset(self::separateConfigs()[$key])) {
            self::$pdo = null;
        } else {
            unset(self::$extra[$key]);
        }
    }

    /** True when the connection accepts XA (two-phase commit) transactions. */
    public static function supportsXa(PDO $pdo): bool
    {
        if ($pdo->inTransaction() || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return false;
        }
        $xid = 'cs2p-probe-' . bin2hex(random_bytes(6));
        try {
            $pdo->exec("XA START '{$xid}'");
            $pdo->exec("XA END '{$xid}'");
            $pdo->exec("XA ROLLBACK '{$xid}'");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check whether a table exists (for optional Zenith/VIPCore integrations).
     * Result memoized for the duration of the request.
     */
    public static function tableExists(string $table, ?string $key = null): bool
    {
        static $cache = [];
        $ck = ($key ?? '') . "\0" . $table;
        if (isset($cache[$ck])) {
            return $cache[$ck];
        }
        try {
            $exists = (bool) self::run(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
                $key
            )->fetchColumn();
        } catch (\Throwable $e) {
            $exists = false;
        }
        return $cache[$ck] = $exists;
    }

    /**
     * Detected database server: ['mariadb' => bool, 'version' => 'x.y.z'].
     * Cached per connection; falls back to MySQL 5.7 when detection fails so
     * callers pick the most portable syntax.
     * @return array{mariadb:bool,version:string}
     */
    public static function serverInfo(?PDO $pdo = null): array
    {
        static $cache = null;
        $cache ??= new \WeakMap();
        $pdo = $pdo ?? self::connection();
        if (isset($cache[$pdo])) {
            return $cache[$pdo];
        }
        try {
            $raw = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (\Throwable $e) {
            $raw = '';
        }
        $mariadb = stripos($raw, 'mariadb') !== false;
        $version = preg_match('/(\d+\.\d+(?:\.\d+)?)/', $raw, $m) ? $m[1] : '5.7.0';
        // Old MariaDB builds report "5.5.5-10.x.y-MariaDB".
        if ($mariadb && preg_match('/^5\.5\.5-(\d+\.\d+(?:\.\d+)?)/', $raw, $m)) {
            $version = $m[1];
        }
        return $cache[$pdo] = ['mariadb' => $mariadb, 'version' => $version];
    }

    public static function isMariaDb(?PDO $pdo = null): bool
    {
        return self::serverInfo($pdo)['mariadb'];
    }

    /** MariaDB 10.0.2+ accepts ADD/DROP COLUMN|INDEX IF [NOT] EXISTS; MySQL does not. */
    public static function supportsAlterIfExists(?PDO $pdo = null): bool
    {
        $info = self::serverInfo($pdo);
        return $info['mariadb'] && version_compare($info['version'], '10.0.2', '>=');
    }

    /** Integer CAST for the detected server (MariaDB: INTEGER, MySQL: SIGNED). */
    public static function castInt(string $expr, ?PDO $pdo = null): string
    {
        return 'CAST(' . $expr . ' AS ' . (self::isMariaDb($pdo) ? 'INTEGER' : 'SIGNED') . ')';
    }

    /**
     * Upsert tail for "INSERT ... VALUES (...)" that updates $columns with the
     * inserted values. MySQL 8.0.19+ uses a row alias (VALUES() is deprecated
     * there since 8.0.20); MariaDB and older MySQL use VALUES(col).
     * @param string[] $columns
     */
    public static function onDuplicateUpdate(array $columns, ?PDO $pdo = null): string
    {
        $info = self::serverInfo($pdo);
        $alias = !$info['mariadb'] && version_compare($info['version'], '8.0.19', '>=');
        $sets = [];
        foreach ($columns as $column) {
            $quoted = '`' . str_replace('`', '``', $column) . '`';
            $sets[] = $quoted . ' = ' . ($alias ? '`_new`.' . $quoted : 'VALUES(' . $quoted . ')');
        }
        return ($alias ? ' AS `_new`' : '') . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
    }

    /** True when a column exists on a table (cached). Safe on missing tables. */
    public static function columnExists(string $table, string $column, ?string $key = null): bool
    {
        static $cache = [];
        $ck = ($key ?? '') . "\0" . $table . '.' . $column;
        if (isset($cache[$ck])) {
            return $cache[$ck];
        }
        try {
            $exists = (bool) self::run(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column],
                $key
            )->fetchColumn();
        } catch (\Throwable $e) {
            $exists = false;
        }
        return $cache[$ck] = $exists;
    }
}
