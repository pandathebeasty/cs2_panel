<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Animated theme registry.
 *
 * Themes are self-contained CSS files dropped into assets/css/themes/ (bundled)
 * or uploaded from Settings > Themes into assets/css/themes/personal/ (personal,
 * kept across updates). Each file declares its metadata in a header comment so
 * the panel can list it in the theme picker without any code change:
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
     * Personal themes uploaded from Settings > Themes. Updates only copy release
     * files (which never ship this folder's content), so they are kept.
     */
    public const PERSONAL_REL_DIR = '/assets/css/themes/personal';

    /** Max size of an uploaded theme file. */
    public const PERSONAL_MAX_BYTES = 1048576;

    /** Ids already used by the built-in presets and the custom palette themes. */
    private const RESERVED_IDS = ['default', 'matrix', 'aurora', 'ember', 'crimson', 'sakura', 'custom'];

    /**
     * Discover all animated themes (bundled + personal). A bundled theme wins
     * over a personal one with the same id.
     * @return array<int, array{id:string, name:string, accent:string, tag:string, file:string, personal:bool, url:string, path:string}>
     */
    public static function all(): array
    {
        $sources = [];
        $dir = self::diskDir();
        if ($dir !== null) {
            $sources[] = [$dir, self::REL_DIR, false];
        }
        $pdir = self::personalDir();
        if (is_dir($pdir)) {
            $sources[] = [$pdir, self::PERSONAL_REL_DIR, true];
        }
        if (!$sources) {
            return [];
        }
        // Key on dirs + file mtimes so added, removed and edited themes are all picked up.
        $key = 'themes2';
        $list = [];
        foreach ($sources as [$abs, $rel, $personal]) {
            $files = glob($abs . '/*.css') ?: [];
            $stamp = (int) @filemtime($abs);
            foreach ($files as $path) {
                $stamp = max($stamp, (int) @filemtime($path));
            }
            $key .= ':' . md5($abs) . ':' . $stamp . ':' . count($files);
            $list[] = [$files, $rel, $personal];
        }
        return Cache::remember($key, 3600, static function () use ($list): array {
            $out = [];
            foreach ($list as [$files, $rel, $personal]) {
                foreach ($files as $path) {
                    $meta = self::parseHeader($path);
                    if ($meta === null || isset($out[$meta['id']])) {
                        continue;
                    }
                    $meta['personal'] = $personal;
                    $meta['url'] = $rel . '/' . $meta['file'];
                    $meta['path'] = $path;
                    $out[$meta['id']] = $meta;
                }
            }
            $out = array_values($out);
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
        $links = [];
        foreach (self::all() as $t) {
            if ($only !== null && $t['id'] !== $only) {
                continue;
            }
            $path = $t['path'];
            $version = Version::current() . (is_file($path) ? '.' . filemtime($path) : '');
            $links[] = Url::to($t['url']) . '?v=' . rawurlencode($version);
        }
        return $links;
    }

    /** Absolute folder of the personal themes (may not exist yet). */
    public static function personalDir(): string
    {
        return PUBLIC_PATH . self::PERSONAL_REL_DIR;
    }

    /**
     * Install an uploaded theme CSS into the personal themes folder. A personal
     * theme with the same id is replaced (lets owners update their theme).
     * @return array{ok:bool, message:string, id?:string}
     */
    public static function installPersonal(string $tmpFile, string $originalName): array
    {
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'css') {
            return ['ok' => false, 'message' => Lang::t('themes.err_ext')];
        }
        $size = (int) @filesize($tmpFile);
        if ($size <= 0 || $size > self::PERSONAL_MAX_BYTES) {
            return ['ok' => false, 'message' => Lang::t('themes.err_size')];
        }
        $css = (string) @file_get_contents($tmpFile);
        // Plain-text CSS only: no binaries and nothing a server could execute.
        if (str_contains($css, "\0") || preg_match('/<\?|<script|<\/style/i', $css) || preg_match('//u', $css) !== 1) {
            return ['ok' => false, 'message' => Lang::t('themes.err_content')];
        }
        $meta = self::parseHeaderString(substr($css, 0, 400), pathinfo($originalName, PATHINFO_FILENAME));
        if ($meta === null) {
            return ['ok' => false, 'message' => Lang::t('themes.err_header')];
        }
        $id = $meta['id'];
        if (in_array($id, self::RESERVED_IDS, true)) {
            return ['ok' => false, 'message' => Lang::t('themes.err_id_taken', ['id' => $id])];
        }
        foreach (self::all() as $t) {
            if ($t['id'] === $id && !$t['personal']) {
                return ['ok' => false, 'message' => Lang::t('themes.err_id_taken', ['id' => $id])];
            }
        }
        $dir = self::personalDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => Lang::t('themes.err_folder')];
        }
        self::protectPersonalDir($dir);
        // A previous upload of the same id may have a different file name.
        foreach (self::all() as $t) {
            if ($t['id'] === $id && $t['personal'] && is_file($t['path'])) {
                @unlink($t['path']);
            }
        }
        if (@file_put_contents($dir . '/' . $id . '.css', $css, LOCK_EX) === false) {
            return ['ok' => false, 'message' => Lang::t('themes.err_folder')];
        }
        @touch($dir);
        return ['ok' => true, 'message' => Lang::t('themes.installed', ['name' => $meta['name']]), 'id' => $id];
    }

    /** Delete a personal theme by id. Bundled themes can never be removed. */
    public static function removePersonal(string $id): bool
    {
        foreach (self::all() as $t) {
            if ($t['id'] === $id && $t['personal'] && is_file($t['path'])) {
                $ok = @unlink($t['path']);
                @touch(self::personalDir());
                return $ok;
            }
        }
        return false;
    }

    /** Serve only .css from the folder and never execute anything in it. */
    private static function protectPersonalDir(string $dir): void
    {
        $ht = $dir . '/.htaccess';
        if (is_file($ht)) {
            return;
        }
        @file_put_contents($ht, "# Personal themes: only CSS files are served.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n    <FilesMatch \"\\.css$\">\n        Require all granted\n    </FilesMatch>\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    <FilesMatch \"\\.css$\">\n        Allow from all\n    </FilesMatch>\n</IfModule>\n");
        @file_put_contents($dir . '/index.html', '');
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
        $meta = self::parseHeaderString($head, pathinfo($path, PATHINFO_FILENAME));
        if ($meta !== null) {
            $meta['file'] = basename($path);
        }
        return $meta;
    }

    /** @return array{id:string, name:string, accent:string, tag:string, file:string}|null */
    private static function parseHeaderString(string $head, string $fallbackId): ?array
    {
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
        $id = $fields['id'] ?? $fallbackId;
        // Only allow a safe id (used as a CSS selector + stored value).
        if (!preg_match('/^[a-z0-9\-]+$/', $id)) {
            return null;
        }
        $accent = $fields['accent'] ?? '#e01e37';
        return [
            'id'     => $id,
            'name'   => $fields['name'] ?? ucfirst(str_replace('-', ' ', $id)),
            // The accent lands in an inline style attribute: keep it a plain colour.
            'accent' => preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent) ? $accent : '#e01e37',
            'tag'    => $fields['tag'] ?? 'Animated',
            'file'   => $id . '.css',
        ];
    }
}
