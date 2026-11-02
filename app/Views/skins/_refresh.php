<?php
/**
 * AJAX loadout-refresh fragment. After an equip, skins.js re-fetches the page
 * and extracts #skinsContainer, #categoryNav and the equipped-data <script>
 * blocks. Rendering ONLY those (no full layout, no catalog chrome) makes the
 * refresh far cheaper than re-rendering the whole page, so equipping feels
 * instant. The variable scope matches skins/index.php.
 *
 * @var array $weapons @var array $skins @var array $knifes @var array $gloves
 * @var array $gloveSkinsAll @var array $groupedWeapons @var array $agentsCT
 * @var array $agentsT @var array $allMusic @var array $allCollectibles
 * @var array $selectedSkins @var array $selectedKnives @var array $selectedGloves
 * @var array $selectedAgents @var array $selectedMusic @var array $selectedPins
 * @var string $csrf
 */

use App\Core\View;
use App\Core\WeaponPaintsData as WP;

/** Same equipped-team badge helper as index.php (section partials use it). */
$teamBadge = static function (int $team): string {
    $cls = $team === WP::TEAM_T ? 'skins-badge-t' : ($team === WP::TEAM_CT ? 'skins-badge-ct' : 'skins-badge-both');
    return '<span class="skins-eq-badge ' . $cls . '">' . View::e(WP::teamShort($team)) . '</span>';
};

/** Equipped-sticker overlay helper (mirror of index.php). */
$stickerIndex = [];
foreach (($allStickers ?? []) as $s) { $stickerIndex[(string) ($s['id'] ?? '')] = $s['image'] ?? ''; }
$keychainIndex = [];
foreach (($allKeychains ?? []) as $k) { $keychainIndex[(string) ($k['id'] ?? '')] = $k['image'] ?? ''; }
$stickerOverlay = static function (array $stickers, array $keychain) use ($stickerIndex, $keychainIndex): string {
    $out = '';
    $row = '';
    foreach ($stickers as $st) {
        $id = (int) ($st['id'] ?? 0);
        if ($id <= 0) { continue; }
        $img = $stickerIndex[(string) $id] ?? '';
        if ($img === '') { continue; }
        $row .= '<img src="' . View::e($img) . '" alt="" loading="lazy">';
    }
    if ($row !== '') { $out .= '<span class="skins-card-stickers">' . $row . '</span>'; }
    $kid = (int) ($keychain['id'] ?? 0);
    if ($kid > 0 && ($keychainIndex[(string) $kid] ?? '') !== '') {
        $out .= '<img class="skins-card-keychain" src="' . View::e($keychainIndex[(string) $kid]) . '" alt="" loading="lazy">';
    }
    return $out;
};
?>
<?php // _sidebar.php emits <nav id="categoryNav">; _sections.php's content is what #skinsContainer holds. ?>
<?php require __DIR__ . '/_sidebar.php'; ?>
<div id="skinsContainer"><?php require __DIR__ . '/_sections.php'; ?></div>
<script id="userMusicEquipped" type="application/json"><?= json_encode($selectedMusic, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script id="userPinsEquipped" type="application/json"><?= json_encode($selectedPins, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
