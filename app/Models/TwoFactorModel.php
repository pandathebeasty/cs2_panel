<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Settings;
use App\Core\Totp;

/**
 * Two-factor authentication (TOTP) for panel staff. Steam stays the primary
 * login; staff with 2FA enabled must also enter an authenticator code.
 */
final class TwoFactorModel
{
    private const TABLE = 'panel_2fa';
    public const MAX_FAILS = 5;
    public const LOCK_SECONDS = 900;
    public const RECOVERY_CODES = 8;

    public static function ensureTable(): void
    {
        Database::createTable(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                `steamid`      VARCHAR(32) NOT NULL,
                `secret`       VARCHAR(64) NOT NULL,
                `recovery`     TEXT NULL,
                `last_step`    BIGINT NOT NULL DEFAULT 0,
                `failed`       INT NOT NULL DEFAULT 0,
                `locked_until` INT UNSIGNED NOT NULL DEFAULT 0,
                `enabled_at`   INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`steamid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    }

    public static function find(string $steamId): ?array
    {
        if ($steamId === '' || !Database::tableExists(self::TABLE)) {
            return null;
        }
        return Database::one('SELECT * FROM `' . self::TABLE . '` WHERE steamid = ?', [$steamId]);
    }

    public static function enabled(string $steamId): bool
    {
        return self::find($steamId) !== null;
    }

    public static function required(): bool
    {
        return Settings::bool('security_require_2fa', false);
    }

    /**
     * Activate 2FA after the user proved the secret works.
     * @return string[] plain recovery codes, shown once
     */
    public static function enable(string $steamId, string $secret, int $step): array
    {
        $codes = self::newRecoveryCodes();
        Database::run(
            'INSERT INTO `' . self::TABLE . '` (steamid, secret, recovery, last_step, failed, locked_until, enabled_at) VALUES (?, ?, ?, ?, 0, 0, ?)'
            . Database::onDuplicateUpdate(['secret', 'recovery', 'last_step', 'failed', 'locked_until', 'enabled_at']),
            [$steamId, $secret, self::hashCodes($codes), $step, time()]
        );
        return $codes;
    }

    public static function disable(string $steamId): void
    {
        Database::run('DELETE FROM `' . self::TABLE . '` WHERE steamid = ?', [$steamId]);
    }

    /** @return string[] */
    public static function regenerateRecovery(string $steamId): array
    {
        $codes = self::newRecoveryCodes();
        Database::run('UPDATE `' . self::TABLE . '` SET recovery = ? WHERE steamid = ?', [self::hashCodes($codes), $steamId]);
        return $codes;
    }

    public static function remainingRecovery(array $row): int
    {
        $list = json_decode((string) ($row['recovery'] ?? '[]'), true);
        return is_array($list) ? count($list) : 0;
    }

    public static function lockedFor(array $row, ?int $now = null): int
    {
        return max(0, (int) $row['locked_until'] - ($now ?? time()));
    }

    /**
     * Verify an authenticator or recovery code, with replay protection and a
     * temporary lock after repeated failures.
     * @return string 'ok' | 'recovery' | 'invalid' | 'locked'
     */
    public static function verify(string $steamId, string $code, ?int $now = null): string
    {
        $now ??= time();
        $row = self::find($steamId);
        if ($row === null) {
            return 'invalid';
        }
        if (self::lockedFor($row, $now) > 0) {
            return 'locked';
        }
        $step = Totp::verify((string) $row['secret'], $code, (int) $row['last_step'], 1, $now);
        if ($step !== null) {
            Database::run('UPDATE `' . self::TABLE . '` SET last_step = ?, failed = 0, locked_until = 0 WHERE steamid = ?', [$step, $steamId]);
            return 'ok';
        }
        if (self::useRecovery($row, $code)) {
            Database::run('UPDATE `' . self::TABLE . '` SET failed = 0, locked_until = 0 WHERE steamid = ?', [$steamId]);
            return 'recovery';
        }
        $failed = (int) $row['failed'] + 1;
        $lock = $failed >= self::MAX_FAILS ? $now + self::LOCK_SECONDS : 0;
        Database::run(
            'UPDATE `' . self::TABLE . '` SET failed = ?, locked_until = ? WHERE steamid = ?',
            [$lock > 0 ? 0 : $failed, $lock, $steamId]
        );
        return $lock > 0 ? 'locked' : 'invalid';
    }

    private static function useRecovery(array $row, string $code): bool
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if (strlen($code) !== 10) {
            return false;
        }
        $list = json_decode((string) ($row['recovery'] ?? '[]'), true);
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $i => $hash) {
            if (is_string($hash) && password_verify($code, $hash)) {
                unset($list[$i]);
                Database::run(
                    'UPDATE `' . self::TABLE . '` SET recovery = ? WHERE steamid = ?',
                    [json_encode(array_values($list)), (string) $row['steamid']]
                );
                return true;
            }
        }
        return false;
    }

    /** @return string[] codes formatted as XXXXX-XXXXX */
    private static function newRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        }
        return $codes;
    }

    private static function hashCodes(array $codes): string
    {
        return json_encode(array_map(
            static fn (string $c): string => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT),
            $codes
        ));
    }
}
