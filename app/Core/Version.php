<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The panel's own version, read from the VERSION file at the project root.
 *
 * Format of VERSION: "<semver> <Codename>", e.g. "1.0.0 Ignition".
 * Releases on GitHub are tagged "v<semver>" (e.g. v1.0.0). Each tag also gets a
 * suggestive codename shown in the UI (see self::CODENAMES).
 */
final class Version
{
    /** GitHub repository that hosts the releases (owner/repo). */
    public const REPO = 'pandathebeasty/cs2_panel';

    /**
     * Release codenames are disabled — the panel shows a plain version number.
     * Kept as an (empty) constant so any external reference still resolves.
     * @var array<string,string>
     */
    public const CODENAMES = [];

    private static ?string $cached = null;

    /** The raw contents of the VERSION file (semver + codename). */
    public static function raw(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $file = BASE_PATH . '/VERSION';
        $raw = is_file($file) ? trim((string) file_get_contents($file)) : '1.0.0';
        return self::$cached = ($raw !== '' ? $raw : '1.0.0');
    }

    /** Just the semver part, e.g. "1.0.0". */
    public static function current(): string
    {
        $parts = preg_split('/\s+/', self::raw());
        return self::normalize($parts[0] ?? '1.0.0');
    }

    /** The codename part, e.g. "Ignition" (falls back to the table, then ""). */
    public static function codename(): string
    {
        $parts = preg_split('/\s+/', self::raw(), 2);
        if (isset($parts[1]) && trim($parts[1]) !== '') {
            return trim($parts[1]);
        }
        return self::codenameFor(self::current());
    }

    /** Codenames are disabled; always returns an empty string. */
    public static function codenameFor(string $semver): string
    {
        return '';
    }

    /** Plain version number for display, e.g. "1.0.0". */
    public static function display(): string
    {
        return self::current();
    }

    /**
     * Strip a leading "v" and keep only digits+dots, so "v1.2.3" -> "1.2.3".
     */
    public static function normalize(string $v): string
    {
        $v = ltrim(trim($v), 'vV');
        // Keep the leading numeric.dotted part only (drop pre-release suffixes).
        if (preg_match('/^\d+(\.\d+)*/', $v, $m)) {
            return $m[0];
        }
        return '0.0.0';
    }

    /**
     * Semantic version compare. Returns -1 if $a < $b, 0 if equal, 1 if $a > $b.
     */
    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }
}
