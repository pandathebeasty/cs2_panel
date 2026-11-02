<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Panel-owned public profile for a Steam account: the Discord handle a player
 * chooses to show on the site. Keyed by SteamID64.
 */
final class ProfileModel
{
    private const TABLE = 'panel_profiles';
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
                    `steamid64` VARCHAR(20) NOT NULL,
                    `discord_tag` VARCHAR(64) NULL,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`steamid64`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] panel_profiles ensure failed: ' . $e->getMessage());
        }
    }

    public static function find(string $steamId64): ?array
    {
        self::ensureTable();
        try {
            return Database::one('SELECT * FROM `' . self::TABLE . '` WHERE steamid64 = ? LIMIT 1', [$steamId64]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function discordTag(string $steamId64): string
    {
        return (string) (self::find($steamId64)['discord_tag'] ?? '');
    }

    /** Discord handles for several accounts at once. @return array<string,string> */
    public static function discordTags(array $steamIds): array
    {
        $steamIds = array_values(array_unique(array_filter($steamIds)));
        if (!$steamIds) {
            return [];
        }
        self::ensureTable();
        $ph = implode(',', array_fill(0, count($steamIds), '?'));
        try {
            $rows = Database::all(
                'SELECT steamid64, discord_tag FROM `' . self::TABLE . '` WHERE steamid64 IN (' . $ph . ')',
                $steamIds
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $tag = trim((string) $r['discord_tag']);
            if ($tag !== '') {
                $out[(string) $r['steamid64']] = $tag;
            }
        }
        return $out;
    }

    /** Store (or clear, when empty) the Discord handle of an account. */
    public static function setDiscordTag(string $steamId64, string $tag): void
    {
        self::ensureTable();
        Database::run(
            'INSERT INTO `' . self::TABLE . '` (steamid64, discord_tag) VALUES (?, ?)'
            . Database::onDuplicateUpdate(['discord_tag']),
            [$steamId64, $tag !== '' ? $tag : null]
        );
    }

    /**
     * A Discord handle is either the modern unique username (2-32 chars of
     * a-z, 0-9, dot and underscore) or the legacy name#1234 form.
     */
    public static function isValidDiscordTag(string $tag): bool
    {
        return (bool) preg_match('/^[a-z0-9._]{2,32}$/', $tag)
            || (bool) preg_match('/^.{2,32}#\d{4}$/u', $tag);
    }
}
