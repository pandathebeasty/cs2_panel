<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Keeps the database schema in step with the deployed code.
 *
 * The self-updater runs the SQL migrations for you, but a panel uploaded by
 * hand (FTP/zip) never went through it, so new tables and columns were missing
 * and pages 500'd. sync() closes that gap: whenever the running code version
 * differs from the one recorded in the database, the pending migrations are
 * applied and the panel-owned tables are (re)created.
 */
final class Schema
{
    /** Setting holding the panel version the database was last synced to. */
    private const VERSION_KEY = 'db_schema_version';

    /** Setting holding the timestamp of the last sync attempt. */
    private const ATTEMPT_KEY = 'db_schema_attempt';

    /** Minimum seconds between two failed sync attempts. */
    private const RETRY_SECONDS = 300;

    /**
     * Bring the database up to date if needed. Cheap no-op once synced (a
     * single cached settings read). Never throws.
     */
    public static function sync(bool $force = false): void
    {
        if (!defined('CS2PANEL_INSTALLED') || CS2PANEL_INSTALLED !== true) {
            return;
        }
        if (DevMode::enabled() && (string) Config::get('db.name', '') === '') {
            return;
        }

        $current = self::marker();
        if (!$force && (string) Settings::get(self::VERSION_KEY, '') === $current) {
            return;
        }

        // If the sync can't complete (e.g. the DB user may not CREATE), don't
        // retry on every single request — that would slow the whole panel down.
        $last = (int) Settings::get(self::ATTEMPT_KEY, '0');
        if (!$force && $last > 0 && (time() - $last) < self::RETRY_SECONDS) {
            return;
        }

        // Only one request migrates; concurrent ones get the "updating" page
        // instead of racing the same ALTER/CREATE INDEX statements.
        $lock = self::lock();
        if ($lock === false) {
            self::updatingPage();
            return;
        }
        try {
            self::run($current, $force);
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    private static function run(string $current, bool $force): void
    {
        try {
            Settings::set(self::ATTEMPT_KEY, (string) time());
        } catch (\Throwable $e) {
            // Can't even write a setting; nothing below will work either.
            return;
        }

        $hasPending = false;
        try {
            $hasPending = Migrator::pending() !== [];
        } catch (\Throwable $e) {
            // If we can't even tell, assume there may be work and back up.
            $hasPending = true;
        }

        // A backup + new indexes on large tables can outlast max_execution_time,
        // which used to 500 the first page load after an update. Lift the limit,
        // keep going if the visitor leaves, and when the SAPI allows it hand the
        // visitor a self-refreshing page first and finish the work afterwards.
        if ($hasPending) {
            @set_time_limit(0);
            @ignore_user_abort(true);
            if (!$force && PHP_SAPI !== 'cli' && self::canFinishEarly()) {
                self::updatingPage(false);
                self::finishRequest();
                self::migrate($current, true);
                exit;
            }
        }
        self::migrate($current, $hasPending);
    }

    private static function migrate(string $current, bool $hasPending): void
    {
        // Back up the database BEFORE touching the schema, but only when there
        // is actually pending work (new migrations). A hand-uploaded panel
        // (FTP/zip) never runs the self-updater, so this is the only safety net
        // that captures the DB before migrations alter it. Skipped on a plain
        // no-op sync so normal page loads never pay for a dump. Best-effort:
        // a failed backup must not block the migrations that follow.
        if ($hasPending) {
            try {
                $path = Backup::full();
                if ($path !== null) {
                    error_log('[CS2Panel] pre-migration DB backup: db_backups/' . basename($path));
                }
            } catch (\Throwable $e) {
                error_log('[CS2Panel] pre-migration backup failed: ' . $e->getMessage());
            }
        }

        try {
            Migrator::runPending();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] schema migrations failed: ' . $e->getMessage());
        }

        self::ensureTables();

                                                                            
        // Pruning uses default/runtime keys and refuses to empty the settings table.
        try {
            Settings::prune();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] settings prune (schema sync) failed: ' . $e->getMessage());
        }

        $pending = Migrator::pending();
        if ($pending) {
            $message = 'Schema sync incomplete; pending migrations: ' . implode(', ', $pending);
            error_log('[CS2Panel] ' . $message);
            @file_put_contents(
                BASE_PATH . '/error.log',
                '[' . date('Y-m-d H:i:s') . '] [CS2Panel] ' . $message . "\n\n",
                FILE_APPEND | LOCK_EX
            );
            return;
        }

        try {
            Settings::set(self::VERSION_KEY, $current);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] schema version write failed: ' . $e->getMessage());
        }
    }

    /** @return resource|false|null lock handle, false when another request holds it, null when locking is unavailable */
    private static function lock()
    {
        $dir = BASE_PATH . '/storage/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $fh = @fopen($dir . '/schema-sync.lock', 'c');
        if ($fh === false) {
            return null;
        }
        if (!@flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }
        return $fh;
    }

    private static function canFinishEarly(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    private static function finishRequest(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
    }

    /** Small self-refreshing 503 page shown while the database is being upgraded. */
    private static function updatingPage(bool $exit = true): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $t = static function (string $key, string $fallback): string {
            try {
                $v = Lang::t($key);
                return htmlspecialchars($v === $key ? $fallback : $v, ENT_QUOTES, 'UTF-8');
            } catch (\Throwable $e) {
                return htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8');
            }
        };
        $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '' || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code(503);
        header('Retry-After: 5');
        header('Cache-Control: no-store');
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo '{"ok":false,"updating":true}';
        } else {
            header('Content-Type: text/html; charset=utf-8');
            $title = $t('schema.updating_title', 'Updating database…');
            $text = $t('schema.updating_text', 'The panel was just updated. This page will reload automatically in a few seconds.');
            echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<meta http-equiv="refresh" content="5"><title>' . $title . '</title><style>'
                . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0d1117;color:#e6edf3;font:15px/1.5 system-ui,sans-serif}'
                . '.b{max-width:420px;padding:32px;text-align:center}.s{width:38px;height:38px;margin:0 auto 18px;border:3px solid #30363d;border-top-color:#e5484d;border-radius:50%;animation:r 1s linear infinite}'
                . 'h1{font-size:20px;margin:0 0 8px}p{margin:0;color:#8b949e}@keyframes r{to{transform:rotate(1turn)}}'
                . '</style></head><body><div class="b"><div class="s"></div><h1>' . $title . '</h1><p>' . $text . '</p></div></body></html>';
        }
        if ($exit) {
            exit;
        }
        @flush();
    }

    /**
     * What the stored marker is compared against: the panel version plus the
     * number of shipped migrations, so dropping in a new .sql file also
     * triggers a sync even when the version string didn't change.
     */
    private static function marker(): string
    {
        $count = count(glob(Migrator::dir() . '/*.sql') ?: []);
        return Version::current() . '|' . $count;
    }

    /**
     * Create/patch every panel-owned table through its model, so a missing
     * migration file can never leave the panel broken. Each model guards its
     * own errors; this only isolates them from one another.
     */
    public static function ensureTables(): void
    {
        $steps = [
            [\App\Core\Logger::class, 'ensureTable'],
            [\App\Models\PanelUserModel::class, 'ensureSchema'],
            [\App\Models\ProfileModel::class, 'ensureTable'],
            [\App\Models\DiscountModel::class, 'ensureTable'],
            [\App\Models\AppealModel::class, 'ensureTable'],
            [\App\Models\ReportModel::class, 'ensureTable'],
            [\App\Models\OrderModel::class, 'ensureTables'],
            [\App\Models\OrderModel::class, 'ensureColumns'],
            [\App\Models\OrderModel::class, 'ensureItemsTable'],
            [\App\Models\ContestModel::class, 'ensureTables'],
            [\App\Models\ContestModel::class, 'ensureColumns'],
            [\App\Models\ServerModel::class, 'ensureRconTable'],
            [\App\Models\BenefitModel::class, 'ensureTable'],
            [\App\Models\ActivityModel::class, 'ensureTable'],
            [\App\Models\VipPendingModel::class, 'ensureTable'],
            [\App\Models\MonitorModel::class, 'ensureTable'],
            [\App\Models\TwoFactorModel::class, 'ensureTable'],
        ];
        foreach ($steps as [$class, $method]) {
            try {
                $class::$method();
            } catch (\Throwable $e) {
                error_log('[CS2Panel] schema ' . $class . '::' . $method . ' failed: ' . $e->getMessage());
            }
        }
    }
}
