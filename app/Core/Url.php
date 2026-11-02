<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Builds URLs that respect the base path (subfolder) the panel runs under.
 * BASE_URL is defined in public/index.php ("" at domain root, "/test" in a subfolder).
 */
final class Url
{
    public static function base(): string
    {
        return defined('BASE_URL') ? BASE_URL : '';
    }

    /**
     * Prefix an app path with the base URL.
     *   url('/bans')  -> '/test/bans'  (or '/bans' at root)
     *   url('/')      -> '/test'       (or '/')
     */
    public static function to(string $path = '/'): string
    {
        $base = self::base();
        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base;
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        return $base . $path;
    }

    /** Absolute origin (scheme://host) without trailing slash. */
    public static function origin(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    /** Full absolute URL for an app path (origin + base + path). */
    public static function full(string $path = '/'): string
    {
        return self::origin() . self::to($path);
    }
}
