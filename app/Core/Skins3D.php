<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Optional 3D preview for the Skins editor.
 *
 * Models (.glb) and paint textures are looked up in order: the local
 * assets/3d/ folder (installed by the optional "3D models" update part),
 * the custom mirrors from Settings, then the public FireFOXXCZ mirror (full
 * texture set, including newer skins) and finally the LielXD mirror (MIT).
 * Each mirror must expose models/{name}.glb and textures/{name}/{paint}.{webp|png}.
 */
final class Skins3D
{
    public const DEFAULT_MIRRORS = [
        'https://raw.githubusercontent.com/FireFOXXCZ/cs2-weaponpaints-website-redesign/main/assets/weapons/',
        'https://raw.githubusercontent.com/LielXD/CS2-WeaponPaints-Website/refs/heads/main/src/',
    ];
    private const MAX_SOURCES = 5;

    public static function enabled(): bool
    {
        return Settings::bool('wp_3d_enabled', false);
    }

    private static function localDir(): string
    {
        return PUBLIC_PATH . '/assets/3d';
    }

    public static function hasLocalModels(): bool
    {
        return is_dir(self::localDir() . '/models');
    }

    /** Valid http(s) base URLs (one per line or comma separated), each ending in "/". @return string[] */
    public static function parseSources(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $url) {
            $url = trim($url);
            if ($url === '' || !preg_match('#^https?://[^\s"\'<>]+$#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $url = rtrim($url, '/') . '/';
            if (!in_array($url, $out, true)) {
                $out[] = $url;
            }
            if (count($out) >= self::MAX_SOURCES) {
                break;
            }
        }
        return $out;
    }

    /**
     * Client config for skins3d.js; null when the preview is disabled.
     * @return array{module:string, sources:array<int,array{models:string, textures:?string}>}|null
     */
    public static function clientConfig(): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $sources = [];
        if (self::hasLocalModels()) {
            $sources[] = [
                'models'   => Url::to('/assets/3d/models/'),
                'textures' => is_dir(self::localDir() . '/textures') ? Url::to('/assets/3d/textures/') : null,
            ];
        }
        foreach (self::parseSources((string) Settings::get('wp_3d_sources', '')) as $base) {
            $sources[] = ['models' => $base . 'models/', 'textures' => $base . 'textures/'];
        }
        foreach (self::DEFAULT_MIRRORS as $mirror) {
            $sources[] = ['models' => $mirror . '%5Bmodels%5D/', 'textures' => $mirror . '%5Btextures%5D/'];
        }

        $file = PUBLIC_PATH . '/assets/js/skins3d.js';
        $ver = Version::current() . (is_file($file) ? '.' . filemtime($file) : '');
        return [
            'module'  => Url::to('/assets/js/skins3d.js') . '?v=' . rawurlencode($ver),
            'sources' => $sources,
        ];
    }
}
