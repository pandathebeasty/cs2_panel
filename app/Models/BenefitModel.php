<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\DevMode;

/**
 * Benefits catalog (panel_benefits table).
 *
 * A simple admin-defined list of perks shown on the public /benefits page and
 * linked from store packages. Each row is one benefit item belonging to a named
 * category; items are grouped by category on the page. The table is created on
 * demand (CREATE TABLE IF NOT EXISTS) so no manual migration is needed.
 *
 * Columns:
 *   id          auto id
 *   category    the card heading / group (e.g. "VIP BRONZE")
 *   icon        emoji / known icon name / uploaded image filename (optional)
 *   description the perk list for that category (long free text, newlines kept)
 *   label       legacy column, kept nullable for older rows (no longer used)
 *   sort_order  manual ordering (ascending)
 *   enabled     1 = shown on the public page
 */
final class BenefitModel
{
    private const TABLE = 'panel_benefits';

    /** Create the table once per request if it's missing, and widen description. */
    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (DevMode::enabled()) {
            return; // dev uses in-memory SQLite; queries no-op safely
        }
        $exists = Database::tableExists(self::TABLE);
        if (!$exists || Database::schemaRepair()) {
            try {
                Database::createTable(
                    'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        category VARCHAR(64) NOT NULL DEFAULT \'\',
                        label VARCHAR(128) NULL,
                        description TEXT NULL,
                        icon VARCHAR(191) NOT NULL DEFAULT \'\',
                        sort_order INT NOT NULL DEFAULT 0,
                        enabled TINYINT(1) NOT NULL DEFAULT 1,
                        PRIMARY KEY (id),
                        KEY idx_category (category),
                        KEY idx_sort (sort_order)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            } catch (\Throwable $e) {
                error_log('[CS2Panel] benefits table create failed: ' . $e->getMessage());
            }
            if (!$exists) {
                return;
            }
        }

        // Mirror the guarded legacy migration without narrowing existing fields.
        try {
            $rows = Database::all(
                'SELECT column_name, column_type, data_type, character_maximum_length, is_nullable
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ?
                   AND column_name IN (\'description\', \'label\', \'icon\')',
                [self::TABLE]
            );
            $columns = array_column($rows, null, 'column_name');
            if (!isset($columns['description'])) {
                Database::run('ALTER TABLE ' . self::TABLE . ' ADD COLUMN `description` TEXT NULL');
            } elseif (!in_array(strtolower((string) $columns['description']['data_type']), ['text', 'mediumtext', 'longtext'], true)) {
                Database::run('ALTER TABLE ' . self::TABLE . ' MODIFY `description` TEXT NULL');
            }
            if (!isset($columns['label'])) {
                Database::run('ALTER TABLE ' . self::TABLE . ' ADD COLUMN `label` VARCHAR(128) NULL');
            } elseif ($columns['label']['is_nullable'] !== 'YES') {
                $type = (string) $columns['label']['column_type'];
                Database::run('ALTER TABLE ' . self::TABLE . ' MODIFY `label` ' . $type . ' NULL');
            }
            if (!isset($columns['icon'])) {
                Database::run('ALTER TABLE ' . self::TABLE . ' ADD COLUMN `icon` VARCHAR(191) NOT NULL DEFAULT \'\'');
            } elseif ($columns['icon']['character_maximum_length'] !== null && (int) $columns['icon']['character_maximum_length'] < 191) {
                $nullability = $columns['icon']['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL DEFAULT \'\'';
                Database::run('ALTER TABLE ' . self::TABLE . ' MODIFY `icon` VARCHAR(191) ' . $nullability);
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] benefits table migrate failed: ' . $e->getMessage());
        }
    }

    /**
     * Every benefit row, ordered for display (by category, then manual order,
     * then id). Used by the admin editor (shows disabled rows too).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        self::ensureTable();
        return Database::all(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY sort_order ASC, id ASC'
        );
    }

    /**
     * Enabled rows in the admin-defined manual order. One row = one card on the
     * public page (no alphabetical sorting — the editor's drag order wins).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function ordered(): array
    {
        self::ensureTable();
        return Database::all(
            'SELECT * FROM ' . self::TABLE . ' WHERE enabled = 1 ORDER BY sort_order ASC, id ASC'
        );
    }

    /** True when at least one enabled benefit exists (to show/hide the page). */
    public static function hasAny(): bool
    {
        self::ensureTable();
        return (int) Database::scalar('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE enabled = 1') > 0;
    }

    /**
     * Replace the whole catalog with the given rows (full-sync from the editor).
     * Simplest reliable persistence for a small, fully-rewritten list: wipe and
     * re-insert inside one transaction. A row needs at least a category or a
     * description to be worth keeping; otherwise it's dropped.
     *
     * @param array<int,array{category?:string,description?:string,icon?:string,enabled?:bool}> $rows
     */
    public static function replaceAll(array $rows): void
    {
        self::ensureTable();
        if (DevMode::enabled()) {
            return;
        }

        try {
            $pdo = Database::connection();
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }

            $pdo->exec('DELETE FROM ' . self::TABLE);

            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (category, label, description, icon, sort_order, enabled)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $order = 0;
            foreach ($rows as $r) {
                $category = trim((string) ($r['category'] ?? ''));
                $description = trim((string) ($r['description'] ?? ''));
                // A card with neither a heading nor any perks is meaningless.
                if ($category === '' && $description === '') {
                    continue;
                }
                $stmt->execute([
                    mb_substr($category, 0, 64),
                    '', // legacy label column — no longer used
                    $description,
                    mb_substr(trim((string) ($r['icon'] ?? '')), 0, 191),
                    $order++,
                    !empty($r['enabled']) ? 1 : 0,
                ]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            try {
                $pdo = Database::connection();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable $ignore) {
            }
            error_log('[CS2Panel] benefits replaceAll failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
