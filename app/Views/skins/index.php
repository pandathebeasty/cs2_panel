<?php
/**
 * WeaponPaints "Skins" loadout editor — panel-native view.
 *
 * Port of d:/CS2/skins/partials/{app,modal}.php rebuilt with the panel's own
 * CSS (no Tailwind). All output is escaped with View::e(); CDN image URLs from
 * the JSON catalog are passed through as-is. The dynamic regions carry the ids
 * #skinsContainer and #categoryNav so skins.js can swap them on AJAX refresh.
 *
 * @var array  $weapons
 * @var array  $skins
 * @var array  $knifes
 * @var array  $gloves
 * @var array  $gloveSkinsAll
 * @var array  $groupedWeapons
 * @var array  $agentsCT
 * @var array  $agentsT
 * @var array  $allMusic
 * @var array  $allCollectibles
 * @var array  $selectedSkins
 * @var array  $selectedKnives
 * @var array  $selectedGloves
 * @var array  $selectedAgents
 * @var array  $selectedMusic
 * @var array  $selectedPins
 * @var string $csrf
 */

use App\Core\Lang;
use App\Core\View;
use App\Core\Version;
use App\Core\WeaponPaintsData as WP;

$title = Lang::t('skins.title');

/** Small helper: equipped-team badge markup (T / CT / ALL). */
$teamBadge = static function (int $team): string {
    $cls = $team === WP::TEAM_T ? 'skins-badge-t' : ($team === WP::TEAM_CT ? 'skins-badge-ct' : 'skins-badge-both');
    return '<span class="skins-eq-badge ' . $cls . '">' . View::e(WP::teamShort($team)) . '</span>';
};

/**
 * Build the equipped-sticker overlay for a weapon card: small thumbnails
 * positioned over the skin image at each sticker's x/y (mapped from [-1,1] to
 * the card's 4..96% box). Keychain renders bottom-right. Returns '' when none.
 * $stickers = array of {id,x,y}; $keychain = {id,x,y}.
 */
$stickerIndex = [];
foreach (($allStickers ?? []) as $s) { $stickerIndex[(string) ($s['id'] ?? '')] = $s['image'] ?? ''; }
$keychainIndex = [];
foreach (($allKeychains ?? []) as $k) { $keychainIndex[(string) ($k['id'] ?? '')] = $k['image'] ?? ''; }
$stickerOverlay = static function (array $stickers, array $keychain) use ($stickerIndex, $keychainIndex): string {
    // Stickers: a tidy row across the TOP of the card. Keychain: its own
    // thumbnail pinned to the BOTTOM-LEFT corner when equipped.
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
<div class="skins-app" data-skins-root>
    <div class="skins-topbar">
        <div class="skins-tabs" id="skinsTabsNav">
            <button type="button" class="skins-tab active" data-top-tab="skins"><?= View::e(Lang::t('skins.nav_weapon_skins')) ?></button>
            <button type="button" class="skins-tab" data-top-tab="agents"><?= View::e(Lang::t('skins.nav_agents')) ?></button>
            <button type="button" class="skins-tab" data-top-tab="music"><?= View::e(Lang::t('skins.nav_music_kits')) ?></button>
            <button type="button" class="skins-tab" data-top-tab="pins"><?= View::e(Lang::t('skins.nav_pins_coins')) ?></button>
        </div>
        <div class="skins-search">
            <input type="text" id="skinsSearchInput" placeholder="<?= View::e(Lang::t('skins.search_placeholder')) ?>" autocomplete="off">
        </div>
    </div>

    <div class="skins-layout">
        <?php require __DIR__ . '/_sidebar.php'; ?>

        <div class="skins-main">
            <div id="skinsContainer">
                <?php require __DIR__ . '/_sections.php'; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_modal.php'; ?>

<script id="musicData" type="application/json"><?= json_encode($allMusic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script id="pinsData" type="application/json"><?= json_encode($allCollectibles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script id="userMusicEquipped" type="application/json"><?= json_encode($selectedMusic, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script id="userPinsEquipped" type="application/json"><?= json_encode($selectedPins, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script id="stickerData" type="application/json"><?= json_encode($allStickers ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script id="keychainData" type="application/json"><?= json_encode($allKeychains ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>window.SKINS_STICKERS_ENABLED = <?= !empty($stickersEnabled) ? 'true' : 'false' ?>;</script>
<?php $skins3d = \App\Core\Skins3D::clientConfig(); if ($skins3d !== null): ?>
<script>window.SKINS_3D = <?= json_encode($skins3d, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php endif; ?>

<script>
window.SKINS_I18N = {
    overview: <?= json_encode(Lang::t('skins.overview')) ?>,
    your_loadout: <?= json_encode(Lang::t('skins.your_loadout')) ?>,
    nav_agents: <?= json_encode(Lang::t('skins.nav_agents')) ?>,
    nav_agents_ct: <?= json_encode(Lang::t('skins.nav_agents_ct')) ?>,
    nav_agents_t: <?= json_encode(Lang::t('skins.nav_agents_t')) ?>,
    nav_music_kits: <?= json_encode(Lang::t('skins.nav_music_kits')) ?>,
    nav_pins_coins: <?= json_encode(Lang::t('skins.nav_pins_coins')) ?>,
    knife_label: <?= json_encode(Lang::t('skins.knife_label')) ?>,
    gloves_label: <?= json_encode(Lang::t('skins.gloves_label')) ?>,
    weapon_label: <?= json_encode(Lang::t('skins.weapon_label')) ?>,
    terrorists: <?= json_encode(Lang::t('skins.terrorists')) ?>,
    counter_terrorists: <?= json_encode(Lang::t('skins.counter_terrorists')) ?>,
    forced_team_note: <?= json_encode(Lang::t('skins.forced_team_note')) ?>,
    saving_ellipsis: <?= json_encode(Lang::t('skins.saving_ellipsis')) ?>,
    equip_skin: <?= json_encode(Lang::t('skins.equip_skin')) ?>,
    toast_skin_equipped: <?= json_encode(Lang::t('skins.toast_skin_equipped')) ?>,
    toast_save_failed: <?= json_encode(Lang::t('skins.toast_save_failed')) ?>,
    showing_items: <?= json_encode(Lang::t('skins.showing_items')) ?>,
    prev: <?= json_encode(Lang::t('skins.prev')) ?>,
    next: <?= json_encode(Lang::t('skins.next')) ?>,
    first: <?= json_encode(Lang::t('skins.first')) ?>,
    last: <?= json_encode(Lang::t('skins.last')) ?>,
    pick_sticker: <?= json_encode(Lang::t('skins.pick_sticker')) ?>,
    pick_keychain: <?= json_encode(Lang::t('skins.pick_keychain')) ?>,
    not_set: <?= json_encode(Lang::t('skins.not_set')) ?>
};
</script>
<?php
$skinsJs = PUBLIC_PATH . '/assets/js/skins.js';
$skinsJsVer = Version::current() . (is_file($skinsJs) ? '.' . filemtime($skinsJs) : '');
?>
<script src="/assets/js/skins.js?v=<?= View::e($skinsJsVer) ?>"></script>
