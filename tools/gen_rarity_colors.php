<?php

declare(strict_types=1);

/**
 * Regenerate app/Data/weaponpaints/rarity_colors.json from ByMykel/CSGO-API.
 *
 * The panel colours each skin card by its in-game rarity (blue Mil-Spec,
 * purple Restricted, pink Classified, red Covert, gold knives/gloves). Those
 * colours are NOT hardcoded — they come from this generated map of
 * paint_index -> { color, rarity }. The panel also refreshes this
 * automatically (weekly, via the cron/maintenance tick) and from the Settings
 * "Check for updates" button; this script is for a manual run or your own cron.
 *
 * Usage (downloads automatically — needs cURL or allow_url_fopen):
 *   php tools/gen_rarity_colors.php
 *
 * Usage with a pre-downloaded file (when the CLI has no outbound network):
 *   php tools/gen_rarity_colors.php path/to/skins.json
 *   (download it first from https://bymykel.github.io/CSGO-API/api/en/skins.json)
 *
 * Source: https://github.com/ByMykel/CSGO-API  (MIT licensed data)
 */

// Bootstrap just enough of the app to reach WeaponPaintsData.
// BASE_PATH is normally set by index.php; define it here for CLI use.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require BASE_PATH . '/app/bootstrap.php';

use App\Core\WeaponPaintsData;

$localArg = $argv[1] ?? '';

if ($localArg !== '') {
    // Local-file mode: rarity colours only, from a downloaded skins.json.
    if (!is_file($localArg)) {
        fwrite(STDERR, "[gen_rarity_colors] input file not found: {$localArg}\n");
        exit(1);
    }
    $result = WeaponPaintsData::refreshRarityColorsFromFile($localArg);
    if (!empty($result['ok'])) {
        fwrite(STDOUT, "[gen_rarity_colors] wrote {$result['count']} paint colours.\n");
        exit(0);
    }
    fwrite(STDERR, "[gen_rarity_colors] ERROR: {$result['error']}\n");
    exit(1);
}

// Full mode: all item JSONs (Nereziel) + rarity colours (ByMykel).
$result = WeaponPaintsData::refreshAll();
if (!empty($result['ok'])) {
    fwrite(STDOUT, "[gen_rarity_colors] updated {$result['updated']} data files + {$result['rarity']} rarity colours.\n");
    exit(0);
}
fwrite(STDERR, "[gen_rarity_colors] ERROR: {$result['error']}\n");
fwrite(STDERR, "  If the server has no outbound network, download skins.json and run:\n");
fwrite(STDERR, "  php tools/gen_rarity_colors.php skins.json   (rarity colours only)\n");
exit(1);
