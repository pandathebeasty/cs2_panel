<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Resolves a CS2 map name to a preview image.
 *
 * Images are served from public/assets/img/maps/<map>.jpg when present.
 * If no dedicated image exists, a null is returned and the view falls back to a
 * generated gradient tile, so the overview always looks intentional.
 */
final class ServerMap
{
    /**
     * Return a web path to the map image, or null if we don't have one.
     * The name is normalized (lowercased, workshop prefixes stripped).
     */
    public static function image(?string $map): ?string
    {
        $slug = self::slug($map);
        if ($slug === '') {
            return null;
        }
        // Prefer .png, fall back to .jpg if that's what was uploaded.
        foreach (['png', 'jpg'] as $ext) {
            $rel = '/assets/img/maps/' . $slug . '.' . $ext;
            // Support both layouts: assets under /public (dev) or web root (deploy).
            foreach (['/public' . $rel, $rel] as $candidate) {
                if (is_file(BASE_PATH . $candidate)) {
                                                                              
                    // CSS URLs bypass View::rewriteBase(), so include the deployment base.
                    return Url::to($rel);
                }
            }
        }
        foreach (['/public/assets/img/maps', '/assets/img/maps'] as $directory) {
            $path = BASE_PATH . $directory;
            if (!is_dir($path)) continue;
            foreach (new \DirectoryIterator($path) as $file) {
                if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['png', 'jpg', 'jpeg', 'webp'], true)) continue;
                if (self::slug($file->getBasename('.' . $file->getExtension())) === $slug) {
                    return Url::to('/assets/img/maps/' . rawurlencode($file->getFilename()));
                }
            }
        }
        return null;
    }

    /**
     * A short, human-friendly label for the map (e.g. "de_dust2" -> "Dust II"
     * when known, otherwise a tidied version of the raw name).
     */
    public static function label(?string $map): string
    {
        $slug = self::slug($map);
        if ($slug === '') {
            return '—';
        }
        return self::KNOWN[$slug] ?? $slug;
    }

    /** Normalize a raw map name into a slug we can match on. */
    public static function slug(?string $map): string
    {
        $map = strtolower(trim((string) $map));
        if ($map === '') {
            return '';
        }
                                                                        
        if (str_contains($map, '/')) {
            $parts = explode('/', $map);
            $map = end($parts);
        }
        // Keep only safe filename characters.
        return preg_replace('/[^a-z0-9_-]/', '', $map) ?? '';
    }

    /** Nice display names for the common competitive/casual maps. */
    private const KNOWN = [
        'de_dust2'    => 'Dust II',
        'de_mirage'   => 'Mirage',
        'de_inferno'  => 'Inferno',
        'de_nuke'     => 'Nuke',
        'de_overpass' => 'Overpass',
        'de_vertigo'  => 'Vertigo',
        'de_ancient'  => 'Ancient',
        'de_anubis'   => 'Anubis',
        'de_train'    => 'Train',
        'de_cache'    => 'Cache',
        'cs_office'   => 'Office',
        'cs_italy'    => 'Italy',
        'cs_agency'   => 'Agency',
    ];
}
