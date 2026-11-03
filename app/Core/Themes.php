<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Animated theme registry.
 *
 * Themes are self-contained CSS files dropped into public/assets/css/themes/.
 * Each file declares its metadata in a header comment so the panel can list it
 * in the theme picker without any code change — just add a .css file:
 *
 *   / * @theme id:aurora-flow | name:Aurora Flow | accent:#4ff0cf | tag:Animated * /
 *
 * The CSS itself is scoped to [data-theme="<id>"] and may run constant
 * animations (gradient shifts, glows, aurora, particles, ...).
 */
final class Themes
{
    public const MODES = ['dark', 'light'];

    public static function mode(): string
    {
        $mode = (string) Settings::get('theme_mode', 'dark');
        if (in_array($mode, self::MODES, true)) return $mode;
        if ($mode !== 'system') error_log('[CS2Panel] Invalid stored theme mode; using dark mode.');
        return 'dark';
    }

    /** Web directory (relative) that holds the theme CSS files. */
    private const REL_DIR = '/assets/css/themes';

    /**
     * Discover all animated themes.
     * @return array<int, array{id:string, name:string, accent:string, tag:string, file:string}>
     */
    public static function all(): array
    {
        $dir = self::diskDir();
        if ($dir === null) {
            return [];
        }
        // Key on dir + file mtimes so added, removed and edited themes are all picked up.
        $files = glob($dir . '/*.css') ?: [];
        $stamp = (int) @filemtime($dir);
        foreach ($files as $path) {
            $stamp = max($stamp, (int) @filemtime($path));
        }
        return Cache::remember('themes:' . md5($dir) . ':' . $stamp . ':' . count($files), 3600, static function () use ($files): array {
            $out = [];
            foreach ($files as $path) {
                $meta = self::parseHeader($path);
                if ($meta !== null) {
                    $out[] = $meta;
                }
            }
            usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
            return $out;
        });
    }

    /**
     * Versioned web URLs of the theme CSS files, for reliable cache busting.
     * $only limits the output to one theme id (pages that cannot switch themes).
     */
    public static function cssLinks(?string $only = null): array
    {
        $dir = self::diskDir();
        if ($dir === null) {
            return [];
        }
        $links = [];
        foreach (self::all() as $t) {
            if ($only !== null && $t['id'] !== $only) {
                continue;
            }
            $path = $dir . '/' . $t['file'];
            $version = Version::current() . (is_file($path) ? '.' . filemtime($path) : '');
            $links[] = Url::to(self::REL_DIR . '/' . $t['file']) . '?v=' . rawurlencode($version);
        }
        return $links;
    }

    /** The on-disk themes directory, or null if it doesn't exist. */
    private static function diskDir(): ?string
    {
        foreach (['/public' . self::REL_DIR, self::REL_DIR] as $candidate) {
            $abs = BASE_PATH . $candidate;
            if (is_dir($abs)) {
                return $abs;
            }
        }
        return null;
    }

    /**
     * Parse the "@theme" header comment of a CSS file.
     * @return array{id:string, name:string, accent:string, tag:string, file:string}|null
     */
    private static function parseHeader(string $path): ?array
    {
        $head = (string) file_get_contents($path, false, null, 0, 400);
        if (!preg_match('/@theme\s+([^*]+)/', $head, $m)) {
            return null;
        }
        $fields = [];
        foreach (explode('|', $m[1]) as $pair) {
            $kv = explode(':', $pair, 2);
            if (count($kv) === 2) {
                $fields[trim($kv[0])] = trim($kv[1]);
            }
        }
        $id = $fields['id'] ?? pathinfo($path, PATHINFO_FILENAME);
        // Only allow a safe id (used as a CSS selector + stored value).
        if (!preg_match('/^[a-z0-9\-]+$/', $id)) {
            return null;
        }
        return [
            'id'     => $id,
            'name'   => $fields['name'] ?? ucfirst(str_replace('-', ' ', $id)),
            'accent' => $fields['accent'] ?? '#e01e37',
            'tag'    => $fields['tag'] ?? 'Animated',
            'file'   => basename($path),
        ];
    }
}
