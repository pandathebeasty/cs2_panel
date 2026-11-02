<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Store discount codes (panel-owned). A code takes a percentage off the cart
 * total and can be limited by a usage count and/or a validity window.
 */
final class DiscountModel
{
    private const TABLE = 'panel_discount_codes';
    private static bool $ensured = false;

    public static function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;
        try {
            Database::run(
                'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `code` VARCHAR(48) NOT NULL,
                    `percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
                    `max_uses` INT NOT NULL DEFAULT 0,
                    `used` INT NOT NULL DEFAULT 0,
                    `starts_at` DATETIME NULL,
                    `ends_at` DATETIME NULL,
                    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `code` (`code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] panel_discount_codes ensure failed: ' . $e->getMessage());
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        self::ensureTable();
        try {
            return Database::all('SELECT * FROM `' . self::TABLE . '` ORDER BY code ASC');
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function findByCode(string $code): ?array
    {
        $code = self::normalize($code);
        if ($code === '') {
            return null;
        }
        self::ensureTable();
        try {
            return Database::one('SELECT * FROM `' . self::TABLE . '` WHERE code = ? LIMIT 1', [$code]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Codes are matched case-insensitively and without surrounding spaces. */
    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', trim($code)) ?? '');
    }

    /**
     * Why a code cannot be used right now, or '' when it is valid.
     * Returns a lang key so the caller decides how to present it.
     */
    public static function rejectReason(?array $row): string
    {
        if ($row === null) {
            return 'store.code_unknown';
        }
        if (!(int) $row['enabled']) {
            return 'store.code_disabled';
        }
        if ((float) $row['percent'] <= 0) {
            return 'store.code_disabled';
        }
        $now = time();
        if (!empty($row['starts_at']) && strtotime((string) $row['starts_at']) > $now) {
            return 'store.code_not_started';
        }
        if (!empty($row['ends_at']) && strtotime((string) $row['ends_at']) < $now) {
            return 'store.code_expired';
        }
        if ((int) $row['max_uses'] > 0 && (int) $row['used'] >= (int) $row['max_uses']) {
            return 'store.code_used_up';
        }
        return '';
    }

    public static function isUsable(?array $row): bool
    {
        return self::rejectReason($row) === '';
    }

    /** Count one redemption. Called once the order is actually paid. */
    public static function markUsed(string $code): void
    {
        $code = self::normalize($code);
        if ($code === '') {
            return;
        }
        self::ensureTable();
        try {
            Database::run('UPDATE `' . self::TABLE . '` SET used = used + 1 WHERE code = ?', [$code]);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] discount markUsed failed: ' . $e->getMessage());
        }
    }

    public static function save(?int $id, string $code, float $percent, int $maxUses, ?string $startsAt, ?string $endsAt, bool $enabled): void
    {
        $code = self::normalize($code);
        if ($code === '') {
            return;
        }
        self::ensureTable();
        $percent = max(0.0, min(100.0, $percent));
        $params = [$code, $percent, max(0, $maxUses), $startsAt ?: null, $endsAt ?: null, $enabled ? 1 : 0];

        if ($id !== null && $id > 0) {
            Database::run(
                'UPDATE `' . self::TABLE . '`
                 SET code = ?, percent = ?, max_uses = ?, starts_at = ?, ends_at = ?, enabled = ?
                 WHERE id = ?',
                array_merge($params, [$id])
            );
            return;
        }
        Database::run(
            'INSERT INTO `' . self::TABLE . '` (code, percent, max_uses, starts_at, ends_at, enabled)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE percent = VALUES(percent), max_uses = VALUES(max_uses),
                 starts_at = VALUES(starts_at), ends_at = VALUES(ends_at), enabled = VALUES(enabled)',
            $params
        );
    }

    public static function delete(int $id): void
    {
        self::ensureTable();
        try {
            Database::run('DELETE FROM `' . self::TABLE . '` WHERE id = ?', [$id]);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] discount delete failed: ' . $e->getMessage());
        }
    }
}
