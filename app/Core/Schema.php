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
        try {
            Settings::set(self::ATTEMPT_KEY, (string) time());
        } catch (\Throwable $e) {
            // Can't even write a setting; nothing below will work either.
            return;
        }

        // Back up the database BEFORE touching the schema, but only when there
        // is actually pending work (new migrations). A hand-uploaded panel
        // (FTP/zip) never runs the self-updater, so this is the only safety net
        // that captures the DB before migrations alter it. Skipped on a plain
        // no-op sync so normal page loads never pay for a dump. Best-effort:
        // a failed backup must not block the migrations that follow.
        $hasPending = false;
        try {
            $hasPending = Migrator::pending() !== [];
        } catch (\Throwable $e) {
            // If we can't even tell, assume there may be work and back up.
            $hasPending = true;
        }
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
