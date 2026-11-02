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
    public static function full(): ?string
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

        $file = $dir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';

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
}
