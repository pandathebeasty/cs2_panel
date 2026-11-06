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
    /** Charm models (keychains/{file}.glb), used after the local and custom copies. */
    public const KEYCHAIN_MIRROR = 'https://raw.githubusercontent.com/FireFOXXCZ/cs2-weaponpaints-website-redesign/main/assets/3d/keychains/';
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

    /** @return float[]|null [xmin, xmax, ymin, ymax, zmin, zmax], or null for an unsupported weapon. */
    public static function keychainLimits(int $defindex, bool $legacy): ?array
    {
        static $data = null;
        if ($data === null) {
            $file = PUBLIC_PATH . '/assets/js/skins3d-keychains.json';
            if (!is_readable($file)) {
                throw new \UnexpectedValueException('Keychain limits file is unavailable.');
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new \UnexpectedValueException('Could not read keychain limits.');
            }
            $decoded = json_decode($contents, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded['limits'] ?? null)) {
                throw new \UnexpectedValueException('Invalid keychain limits data.');
            }
            $data = $decoded['limits'];
        }

        $weapon = $data[$defindex] ?? null;
        if ($weapon === null) {
            return null;
        }
        if (!is_array($weapon)) {
            throw new \UnexpectedValueException('Invalid keychain limits for weapon ' . $defindex);
        }
        $limits = $legacy && isset($weapon['legacy']) ? $weapon['legacy'] : ($weapon['hd'] ?? null);
        if (!is_array($limits) || array_keys($limits) !== range(0, 5)) {
            throw new \UnexpectedValueException('Invalid keychain limits for weapon ' . $defindex);
        }
        foreach ($limits as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new \UnexpectedValueException('Invalid keychain bounds for weapon ' . $defindex);
            }
        }
        for ($i = 0; $i < 6; $i += 2) {
            if ($limits[$i] > $limits[$i + 1]) {
                throw new \UnexpectedValueException('Reversed keychain bounds for weapon ' . $defindex);
            }
        }
        return array_map('floatval', $limits);
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
     * @return array{module:string, sources:array<int,array{models:string, textures:?string}>, keychains:string[]}|null
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

        $keychains = [];
        if (is_dir(self::localDir() . '/keychains')) {
            $keychains[] = Url::to('/assets/3d/keychains/');
        }
        foreach (self::parseSources((string) Settings::get('wp_3d_sources', '')) as $base) {
            $keychains[] = $base . 'keychains/';
        }
        $keychains[] = self::KEYCHAIN_MIRROR;

        $file = PUBLIC_PATH . '/assets/js/skins3d.js';
        $ver = Version::current() . (is_file($file) ? '.' . filemtime($file) : '');
        return [
            'module'    => Url::to('/assets/js/skins3d.js') . '?v=' . rawurlencode($ver),
            'sources'   => $sources,
            'keychains' => $keychains,
        ];
    }
}
