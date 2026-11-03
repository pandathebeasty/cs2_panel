<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small TTL cache: APCu when available, otherwise one file per key under
 * storage/cache (blocked from the web by .htaccess). Failures are silent so
 * callers simply recompute.
 */
final class Cache
{
    private static ?bool $apcu = null;
    private static array $memory = [];

    private static function useApcu(): bool
    {
        return self::$apcu ??= function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    private static function dir(): string
    {
        return BASE_PATH . '/storage/cache';
    }

    private static function prefix(): string
    {
        return 'cs2panel:' . substr(md5(BASE_PATH), 0, 8) . ':';
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.cache';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$memory)) {
            [$expires, $value] = self::$memory[$key];
            if ($expires === 0 || $expires >= time()) {
                return $value;
            }
            unset(self::$memory[$key]);
        }
        if (self::useApcu()) {
            $ok = false;
            $value = apcu_fetch(self::prefix() . $key, $ok);
            return $ok ? $value : $default;
        }
        $file = self::file($key);
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['e']) || ($data['e'] !== 0 && $data['e'] < time())) {
            @unlink($file);
            return $default;
        }
        self::$memory[$key] = [$data['e'], $data['v']];
        return $data['v'];
    }

    public static function set(string $key, mixed $value, int $ttl = 60): void
    {
        $expires = $ttl > 0 ? time() + $ttl : 0;
        self::$memory[$key] = [$expires, $value];
        if (self::useApcu()) {
            @apcu_store(self::prefix() . $key, $value, max(0, $ttl));
            return;
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
            // Updates do not ship storage/, so protect it on first use.
            $guard = dirname($dir) . '/.htaccess';
            if (!is_file($guard)) {
                @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
            }
        }
        $tmp = $dir . '/' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, serialize(['e' => $expires, 'v' => $value]), LOCK_EX) !== false) {
            @rename($tmp, self::file($key));
        } else {
            @unlink($tmp);
        }
    }

    /** Return the cached value or compute and store it. */
    public static function remember(string $key, int $ttl, callable $compute): mixed
    {
        $miss = "\0miss";
        $value = self::get($key, $miss);
        if ($value !== $miss) {
            return $value;
        }
        $value = $compute();
        self::set($key, $value, $ttl);
        return $value;
    }

    public static function forget(string $key): void
    {
        unset(self::$memory[$key]);
        if (self::useApcu()) {
            @apcu_delete(self::prefix() . $key);
            return;
        }
        @unlink(self::file($key));
    }

    /** Remove every cached entry (e.g. after an update). */
    public static function flush(): int
    {
        self::$memory = [];
        if (self::useApcu()) {
            $removed = 0;
            if (class_exists('\APCUIterator')) {
                foreach (new \APCUIterator('/^' . preg_quote(self::prefix(), '/') . '/') as $item) {
                    $removed += apcu_delete($item['key']) ? 1 : 0;
                }
            }
            return $removed;
        }
        $removed = 0;
        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            $removed += @unlink($file) ? 1 : 0;
        }
        return $removed;
    }

    /** Delete expired cache files; called from Maintenance. */
    public static function prune(): int
    {
        if (self::useApcu()) {
            return 0;
        }
        $removed = 0;
        $now = time();
        foreach (array_merge(glob(self::dir() . '/*.cache') ?: [], glob(self::dir() . '/*.tmp') ?: []) as $file) {
            if (str_ends_with($file, '.tmp')) {
                if (@filemtime($file) < $now - 3600 && @unlink($file)) $removed++;
                continue;
            }
            $data = @unserialize((string) @file_get_contents($file), ['allowed_classes' => false]);
            if (!is_array($data) || (($data['e'] ?? 0) !== 0 && $data['e'] < $now)) {
                $removed += @unlink($file) ? 1 : 0;
            }
        }
        return $removed;
    }
}
