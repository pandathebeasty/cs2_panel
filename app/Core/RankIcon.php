<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Renders a player's rank for the leaderboards (Stats page, dashboard top list),
 * for BOTH stats backends (K4-Zenith and K4-System) — each stores the rank as a
 * text name (e.g. "Gold Nova I").
 *
 * Display:
 *   - If a rank IMAGE exists at assets/img/ranks/<slug>.(png|svg|webp) it is used
 *     (drop the real CS2 skill-group icons there — see assets/img/ranks/README).
 *   - Otherwise a lightweight CSS badge is drawn (short tag coloured by tier), so
 *     the panel works out of the box without shipping any proprietary artwork.
 *
 * Two styles, chosen by the stats "rank_type" setting:
 *   - 'competitive' : skill-group image/badge (default).
 *   - 'premier'     : a CS2-Premier rating pill showing the points value.
 */
final class RankIcon
{
    /**
     * CS:GO/CS2 competitive ladder (matches K4-System default-ranks.jsonc order).
     * key = lowercased rank name => [index, short tag, tier, image slug].
     * @var array<string, array{0:int,1:string,2:string,3:string}>
     */
    private const RANKS = [
        'silver i'                      => [1,  'S1',   'silver', 'silver-1'],
        'silver ii'                     => [2,  'S2',   'silver', 'silver-2'],
        'silver iii'                    => [3,  'S3',   'silver', 'silver-3'],
        'silver iv'                     => [4,  'S4',   'silver', 'silver-4'],
        'silver elite'                  => [5,  'SE',   'silver', 'silver-elite'],
        'silver elite master'           => [6,  'SEM',  'silver', 'silver-elite-master'],
        'gold nova i'                   => [7,  'GN1',  'gold',   'gold-nova-1'],
        'gold nova ii'                  => [8,  'GN2',  'gold',   'gold-nova-2'],
        'gold nova iii'                 => [9,  'GN3',  'gold',   'gold-nova-3'],
        'gold nova master'              => [10, 'GNM',  'gold',   'gold-nova-master'],
        'master guardian i'             => [11, 'MG1',  'green',  'master-guardian-1'],
        'master guardian ii'            => [12, 'MG2',  'green',  'master-guardian-2'],
        'master guardian elite'         => [13, 'MGE',  'green',  'master-guardian-elite'],
        'distinguished master guardian' => [14, 'DMG',  'green',  'distinguished-master-guardian'],
        'legendary eagle'               => [15, 'LE',   'blue',   'legendary-eagle'],
        'legendary eagle master'        => [16, 'LEM',  'blue',   'legendary-eagle-master'],
        'supreme master first class'    => [17, 'SMFC', 'purple', 'supreme-master-first-class'],
        'global elite'                  => [18, 'GE',   'red',    'global-elite'],
    ];

    /** Image extensions tried, in order of preference. */
    private const EXTENSIONS = ['png', 'webp', 'svg'];

    /** Per-request cache of which rank image files exist. @var array<string,?string> */
    private static array $imgCache = [];

    /**
     * Build the rank cell HTML.
     *
     * @param string   $rankName the stored rank name (may be '', 'None', 'null')
     * @param string   $type     'competitive' | 'premier'
     * @param int|null $points   player's points (used for the premier rating)
     */
    public static function html(string $rankName, string $type = 'competitive', ?int $points = null): string
    {
        $name = self::cleanName($rankName);
        $isEmpty = self::isNone($rankName);

        // Premier: show the CS2 CS Rating (points) on a badge coloured by the
        // official 7-band tier (grey -> gold), exactly like the in-game Premier
        // rating and css-bans. A per-band image at assets/img/ranks/premier-<band>.*
        // (or a generic premier.*) is preferred when present.
        if ($type === 'premier') {
            if ($points === null && $isEmpty) {
                return '<span class="muted">—</span>';
            }
            $rating = $points !== null ? $points : (int) preg_replace('/[^0-9]/', '', $name);
            $band   = self::premierBand($rating);
            $label  = $points !== null ? number_format($points) : View::e($name);

            $img = self::imageUrl('premier-' . $band) ?? self::imageUrl('premier');
            if ($img !== null) {
                // CS Rating number overlaid on the official Premier badge.
                return '<span class="rank-premier-img rank-premier-' . $band . '" title="CS2 Premier Rating">'
                    . '<img class="cs2rating" src="' . View::e($img) . '" alt="Premier" loading="lazy">'
                    . '<span class="rank-premier-val">' . $label . '</span></span>';
            }
            return '<span class="rank-premier rank-premier-' . $band . '" title="CS2 Premier Rating">' . $label . '</span>';
        }

        // Unranked / "None": show the none badge image if present, else a dash.
        if ($isEmpty) {
            $img = self::imageUrl('none');
            if ($img !== null) {
                return '<img class="rank-img rank-img-none" src="' . View::e($img) . '" alt="None" title="None" loading="lazy">';
            }
            return '<span class="rank-badge rank-silver" title="None">None</span>';
        }

        [$idx, $tag, $tier, $slug] = self::RANKS[strtolower($name)] ?? [0, null, 'silver', null];

        // Prefer a real rank image when the file exists.
        if ($slug !== null) {
            $img = self::imageUrl($slug);
            if ($img !== null) {
                return '<img class="rank-img" src="' . View::e($img) . '" alt="' . View::e($name) . '" title="' . View::e($name) . '" loading="lazy">';
            }
        }

        // Fallback: CSS badge. Unknown rank name -> neutral badge with full name.
        if ($tag === null) {
            return '<span class="rank-badge rank-silver" title="' . View::e($name) . '">' . View::e($name) . '</span>';
        }
        return '<span class="rank-badge rank-' . $tier . '" title="' . View::e($name) . '">' . View::e($tag) . '</span>';
    }

    /**
     * Clean rank display name. Turns raw plugin phrase keys (e.g.
     * "k4.phrases.rank.none") and empty/null markers into a tidy label, and
     * title-cases a plain rank name. Returns 'None' for unranked players.
     */
    public static function cleanName(string $rankName): string
    {
        if (self::isNone($rankName)) {
            return 'None';
        }
        return trim($rankName);
    }

    /**
     * True when the stored rank means "no rank": empty, 'none'/'null', or an
     * unresolved plugin translation key such as "k4.phrases.rank.none"
     * (K4-System stores the phrase key when a player has no rank yet).
     */
    public static function isNone(string $rankName): bool
    {
        $n = strtolower(trim($rankName));
        if ($n === '' || $n === 'none' || $n === 'null' || $n === 'n/a' || $n === 'na') {
            return true;
        }
        // Any "...rank.none" / "...none" phrase key, or a leftover "*.phrases.*" key.
        if (preg_match('/(^|\.)none$/', $n) || str_contains($n, 'phrases.rank')) {
            return true;
        }
        return false;
    }

    /**
     * Resolve a web URL for a rank image slug if a file exists under
     * assets/img/ranks/, else null. Result is cached per request.
     */
    private static function imageUrl(string $slug): ?string
    {
        if (array_key_exists($slug, self::$imgCache)) {
            return self::$imgCache[$slug];
        }
        $base = defined('PUBLIC_PATH') ? PUBLIC_PATH : '';
        $url = null;
        if ($base !== '') {
            foreach (self::EXTENSIONS as $ext) {
                $rel = '/assets/img/ranks/' . $slug . '.' . $ext;
                if (is_file($base . $rel)) {
                    $url = (defined('BASE_URL') ? BASE_URL : '') . $rel;
                    break;
                }
            }
        }
        return self::$imgCache[$slug] = $url;
    }

    /**
     * Map a CS2 Premier CS Rating to its colour band (7 bands of 5,000 points,
     * grey -> gold), matching the in-game rating colours.
     *   grey   0 – 4,999      lightblue 5,000 – 9,999   blue 10,000 – 14,999
     *   purple 15,000 –19,999 pink      20,000 –24,999   red  25,000 –29,999
     *   gold   30,000+
     */
    private static function premierBand(int $rating): string
    {
        return match (true) {
            $rating >= 30000 => 'gold',
            $rating >= 25000 => 'red',
            $rating >= 20000 => 'pink',
            $rating >= 15000 => 'purple',
            $rating >= 10000 => 'blue',
            $rating >= 5000  => 'lightblue',
            default          => 'grey',
        };
    }
}
