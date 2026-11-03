<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Full database backup written as a plain .sql dump using PDO only
 * (no mysqldump binary required, so it works on shared hosting).
 *
 * Files are written to <BASE_PATH>/db_backups/backup_YYYY-MM-DD_HH-mm-ss.sql
 * i.e. right where the site lives, in a "db_backups" folder.
 */
final class Backup
{
    /** Absolute path to the backups directory (next to the app). */
    public static function dir(): string
    {
        return BASE_PATH . '/db_backups';
    }

    /**
     * Dump every table (structure + data) to a timestamped .sql file.
     * Returns the absolute file path, or null if the backup could not be made.
     */
    public static function full(string $prefix = 'backup'): ?string
    {
        // Never attempt a real dump against the dev in-memory SQLite.
        if (DevMode::enabled() && (string) Config::get('db.name', '') === '') {
            return null;
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[CS2Panel] backup: cannot create ' . $dir);
            return null;
        }

        $file = $dir . '/' . preg_replace('/[^a-z0-9_]/i', '', $prefix) . '_' . date('Y-m-d_H-i-s') . '.sql';

        // A full dump of a large database can take minutes. A fixed time limit
        // killed it with a fatal 500 before the contest reset could run, so the
        // dump runs without one and survives a closed browser tab.
        @ignore_user_abort(true);
        @set_time_limit(0);
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit !== -1 && $limit < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }

        $handle = false;
        $partial = $file . '.part';
        try {
            $pdo = Database::connection();
            // Written to a temporary name and renamed only when complete, so a
            // failed dump never looks like a valid backup.
            $handle = @fopen($partial, 'w');
            if ($handle === false) {
                error_log('[CS2Panel] backup: cannot write ' . $partial);
                return null;
            }

            $dbName = (string) Config::get('db.name', '');
            self::write($handle, "-- CS2 Panel database backup\n"
                . '-- Database: ' . $dbName . "\n"
                . '-- Generated: ' . date('Y-m-d H:i:s') . "\n\n"
                . "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                self::dumpTable($pdo, $handle, (string) $table);
            }

            self::write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            if (!fclose($handle)) {
                $handle = false;
                throw new \RuntimeException('cannot finish writing ' . $partial);
            }
            $handle = false;
            if (!@rename($partial, $file)) {
                throw new \RuntimeException('cannot rename ' . $partial . ' to ' . $file);
            }
            return $file;
        } catch (\Throwable $e) {
            error_log('[CS2Panel] backup failed: ' . $e->getMessage());
            if ($handle !== false) {
                @fclose($handle);
            }
            @unlink($partial);
            // Make sure the connection is back in buffered mode so the caller's
            // next query (e.g. the reset) doesn't hit "commands out of sync".
            try {
                if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                    Database::connection()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
            } catch (\Throwable $ignored) {
            }
            return null;
        }
    }

    /** Write all bytes or fail, so a full disk cannot produce a silently truncated dump. */
    private static function write($handle, string $data): void
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, $offset === 0 ? $data : substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('write failed (disk full or no permission)');
            }
            $offset += $written;
        }
    }

    /** php.ini size ("512M", "1G", "-1") in bytes; -1 means unlimited. */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** Write one table's CREATE + INSERT statements to the open handle. */
    private static function dumpTable(PDO $pdo, $handle, string $table): void
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        self::write($handle, "\n-- ----------------------------\n"
            . "-- Table: {$table}\n"
            . "-- ----------------------------\n"
            . "DROP TABLE IF EXISTS {$quoted};\n");

        // Structure.
        $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_ASSOC);
        $createSql = $create['Create Table'] ?? ($create['Create View'] ?? '');
        if ($createSql !== '') {
            self::write($handle, $createSql . ";\n\n");
        }

        // Data, streamed row by row so large tables don't exhaust memory.
        // With the default buffered mode PDO loads the ENTIRE table into memory
        // on query(), which can fatal-OOM on big tables (bans/zenith storage).
        // Switch to an unbuffered cursor for this SELECT, then restore it.
        $canUnbuffer = defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
        if ($canUnbuffer) {
            @$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        try {
            $stmt = $pdo->query("SELECT * FROM {$quoted}");
            // Rows are grouped into multi-row INSERTs (about 1 MB each), which
            // makes both the dump and a later restore much faster than one
            // statement per row.
            $prefix = null;
            $batch = '';
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($prefix === null) {
                    $cols = array_map(
                        static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`',
                        array_keys($row)
                    );
                    $prefix = "INSERT INTO {$quoted} (" . implode(', ', $cols) . ') VALUES ';
                }
                $vals = array_map(
                    static fn($v): string => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    array_values($row)
                );
                $batch .= ($batch === '' ? $prefix : ",\n") . '(' . implode(', ', $vals) . ')';
                if (strlen($batch) >= 1048576) {
                    self::write($handle, $batch . ";\n");
                    $batch = '';
                }
            }
            if ($batch !== '') {
                self::write($handle, $batch . ";\n");
            }
            $stmt->closeCursor();
        } finally {
            // Always restore buffered mode, even if the dump of this table failed.
            if ($canUnbuffer) {
                @$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
    }

    public const AUTO_PREFIX = 'backup_auto';
    public const FREQUENCIES = ['hourly', 'daily', 'weekly'];

    /**
     * Backup files in db_backups/, newest first.
     * @return array<int,array{name:string,size:int,mtime:int,type:string}>
     */
    public static function files(): array
    {
        $out = [];
        foreach (@scandir(self::dir()) ?: [] as $name) {
            if (!preg_match('/\.(sql|sql\.gz|zip)$/', $name)) {
                continue;
            }
            $path = self::dir() . '/' . $name;
            $type = str_starts_with($name, 'code_') ? 'code'
                : (str_starts_with($name, self::AUTO_PREFIX . '_') ? 'auto' : 'manual');
            $out[] = ['name' => $name, 'size' => (int) @filesize($path), 'mtime' => (int) @filemtime($path), 'type' => $type];
        }
        usort($out, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    /** Absolute path of a backup by file name, or null when it is not a backup. */
    public static function path(string $name): ?string
    {
        $name = basename($name);
        if (!preg_match('/^[A-Za-z0-9_.\-]+\.(sql|sql\.gz|zip)$/', $name)) {
            return null;
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** Gzip a .sql dump (streamed), delete the original and return the .sql.gz path. */
    public static function compress(string $file): ?string
    {
        if (!function_exists('gzopen')) {
            return null;
        }
        $target = $file . '.gz';
        $in = @fopen($file, 'rb');
        $out = @gzopen($target . '.part', 'wb6');
        $ok = $in !== false && $out !== false;
        while ($ok && !feof($in)) {
            $chunk = fread($in, 1048576);
            $ok = $chunk !== false && gzwrite($out, $chunk) !== false;
        }
        if ($in !== false) {
            fclose($in);
        }
        if ($out !== false) {
            gzclose($out);
        }
        if (!$ok || !@rename($target . '.part', $target)) {
            @unlink($target . '.part');
            return null;
        }
        @unlink($file);
        return $target;
    }

    /**
     * Run a dump made by full() (plain or gzipped) against the database.
     * Statements end with ";" at the end of a line; string values never contain
     * raw newlines because PDO::quote escapes them.
     * @return array{ok:bool,message:string,statements:int}
     */
    public static function restore(string $file): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $gz = str_ends_with($file, '.gz');
        $handle = $gz ? @gzopen($file, 'rb') : @fopen($file, 'rb');
        if ($handle === false) {
            return ['ok' => false, 'message' => 'Cannot open ' . basename($file), 'statements' => 0];
        }
        $count = 0;
        $buffer = '';
        try {
            $pdo = Database::connection();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            while (($line = $gz ? gzgets($handle) : fgets($handle)) !== false) {
                if ($buffer === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                    continue;
                }
                $buffer .= $line;
                if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                    $pdo->exec($buffer);
                    $buffer = '';
                    $count++;
                }
            }
            if (trim($buffer) !== '') {
                $pdo->exec($buffer);
                $count++;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (\Throwable $e) {
            try {
                Database::connection()->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (\Throwable $ignored) {
            }
            $gz ? gzclose($handle) : fclose($handle);
            return ['ok' => false, 'message' => $e->getMessage(), 'statements' => $count];
        }
        $gz ? gzclose($handle) : fclose($handle);
        return ['ok' => true, 'message' => 'ok', 'statements' => $count];
    }

    /** Delete the oldest automatic backups, keeping $keep of them. */
    public static function prune(int $keep): int
    {
        $auto = array_values(array_filter(self::files(), static fn(array $f): bool => $f['type'] === 'auto'));
        $removed = 0;
        foreach (array_slice($auto, max(1, $keep)) as $f) {
            if (@unlink(self::dir() . '/' . $f['name'])) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Delete backups older than $days (0 = never). The newest database backup
     * and the files referenced by the current update rollback are always kept.
     */
    public static function expire(int $days, ?int $now = null): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = ($now ?? time()) - $days * 86400;
        $protected = [];
        try {
            $rb = Updater::rollbackInfo();
            if ($rb) {
                $protected[] = $rb['code_backup'];
                if ($rb['db_backup'] !== '') $protected[] = $rb['db_backup'];
            }
        } catch (\Throwable $e) {
        }
        $newestDb = null;
        foreach (self::files() as $f) {
            if ($f['type'] !== 'code') {
                $newestDb = $f['name'];
                break;
            }
        }
        $removed = 0;
        foreach (self::files() as $f) {
            if ($f['mtime'] >= $cutoff || $f['name'] === $newestDb || in_array($f['name'], $protected, true)) {
                continue;
            }
            if (@unlink(self::dir() . '/' . $f['name'])) {
                $removed++;
            }
        }
        if ($removed > 0) {
            Logger::log('backup.expired', null, $removed . ' file(s) older than ' . $days . ' days');
        }
        return $removed;
    }
    public static function lastSlot(string $frequency, string $time, int $weekday, int $now): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            $m = [0, 3, 0];
        }
        $hour = max(0, min(23, (int) $m[1]));
        $minute = max(0, min(59, (int) $m[2]));
        $d = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        if ($frequency === 'hourly') {
            $slot = $d->setTime((int) $d->format('H'), $minute);
            return ($slot->getTimestamp() > $now ? $slot->modify('-1 hour') : $slot)->getTimestamp();
        }
        $slot = $d->setTime($hour, $minute);
        if ($frequency === 'weekly') {
            $diff = ((int) $d->format('N') - max(1, min(7, $weekday)) + 7) % 7;
            $slot = $slot->modify('-' . $diff . ' days');
            return ($slot->getTimestamp() > $now ? $slot->modify('-7 days') : $slot)->getTimestamp();
        }
        return ($slot->getTimestamp() > $now ? $slot->modify('-1 day') : $slot)->getTimestamp();
    }

    /** Run the scheduled backup when its slot is due. Called from Maintenance. */
    public static function scheduledTick(?int $now = null): ?string
    {
        if (!Settings::bool('backup_auto_enabled', false)) {
            return null;
        }
        $now ??= time();
        $last = (int) Settings::get('backup_auto_last', '0');
        if ($last === 0) {
            // First tick after enabling: start counting from now.
            Settings::set('backup_auto_last', (string) $now);
            return null;
        }
        $slot = self::lastSlot(
            (string) Settings::get('backup_auto_frequency', 'daily'),
            (string) Settings::get('backup_auto_time', '03:00'),
            (int) Settings::get('backup_auto_weekday', '1'),
            $now
        );
        if ($last >= $slot) {
            return null;
        }
        // Claim the slot first so a parallel tick does not start a second dump.
        Settings::set('backup_auto_last', (string) $now);
        $file = self::full(self::AUTO_PREFIX);
        if ($file === null) {
            Settings::set('backup_auto_status', 'error|' . $now);
            Logger::log('backup.auto_failed');
            return null;
        }
        if (Settings::bool('backup_auto_compress', true)) {
            $file = self::compress($file) ?? $file;
        }
        self::prune(max(1, (int) Settings::get('backup_auto_keep', '7')));
        Settings::set('backup_auto_status', 'ok|' . $now . '|' . basename($file));
        return $file;
    }
}
