<?php
/**
 * Skins sections: the overview grid plus one hidden section per weapon/knife/
 * glove, the agents (CT + T), and the client-rendered Music/Pins containers.
 * All markup is panel-native (no Tailwind). Escaped with View::e().
 *
 * Variables come from SkinsController::index(). $teamBadge is the closure
 * defined in index.php (same render scope).
 *
 * @var array    $weapons
 * @var array    $skins
 * @var array    $knifes
 * @var array    $gloves
 * @var array    $gloveSkinsAll
 * @var array    $agentsCT
 * @var array    $agentsT
 * @var array    $selectedSkins
 * @var array    $selectedKnives
 * @var array    $selectedGloves
 * @var array    $selectedAgents
 * @var callable $teamBadge
 */

use App\Core\Lang;
use App\Core\View;
use App\Core\WeaponPaintsData as WP;
?>
<!-- Overview -->
<section id="skinsOverviewSection">
    <p class="skins-section-desc"><?= View::e(Lang::t('skins.overview_description')) ?></p>
    <div class="skins-grid">
        <?php foreach ($weapons as $defindex => $default):
            $forcedTeam = WP::getForcedTeam((int) $defindex);
            $allRows = $selectedSkins[$defindex] ?? [];
            // Which equipped rows to show as cards:
            //  - forced-team weapon: its single forced-team row;
            //  - per-team rows (T/CT) present: show EACH (a weapon can run a
            //    different skin per team); a leftover ALL is stale -> ignored;
            //  - only an ALL row: that single card;
            //  - nothing equipped: one "default/not set" card.
            $ovRows = [];
            if ($forcedTeam) {
                if (isset($allRows[$forcedTeam])) { $ovRows[] = $allRows[$forcedTeam]; }
            } else {
                $pt = [];
                foreach ($allRows as $tId => $r) {
                    if ((int) $tId === WP::TEAM_T || (int) $tId === WP::TEAM_CT) { $pt[] = $r; }
                }
                if (!empty($pt)) { $ovRows = $pt; }
                elseif (isset($allRows[WP::TEAM_BOTH])) { $ovRows[] = $allRows[WP::TEAM_BOTH]; }
            }
            if (empty($ovRows)) { $ovRows[] = null; } // not-set placeholder
            foreach ($ovRows as $equipped):
                $paint = $equipped ? ($skins[$defindex][$equipped['weapon_paint_id']] ?? $default) : $default;
                $team = $equipped['weapon_team'] ?? null;
        ?>
            <?php $ovColor = (string) ($paint['color'] ?? ''); ?>
            <button type="button" class="skins-card<?= $equipped ? ' is-equipped' : '' ?>" data-weapon-nav data-defindex="<?= (int) $defindex ?>"<?= $ovColor !== '' ? ' data-rarity style="--rarity:' . View::e($ovColor) . '"' : '' ?>>
                <div class="skins-card-img">
                    <img src="<?= View::e($paint['image_url']) ?>" alt="" loading="lazy" decoding="async">
                    <?php if ($equipped): ?><?= $stickerOverlay($equipped['stickers'] ?? [], $equipped['keychain'] ?? []) ?><?php endif; ?>
                    <?php if ($equipped && isset($equipped['weapon_wear'])): ?>
                        <span class="skins-wear"><?= View::e(WP::wearLabelShort((float) $equipped['weapon_wear'])) ?></span>
                    <?php endif; ?>
                </div>
                <p class="skins-card-name"><?= View::e($default['display_name']) ?></p>
                <p class="skins-card-sub"><?= View::e(trim((string) $paint['paint_name'])) ?></p>
                <?php if ($equipped && !empty($equipped['weapon_nametag'])): ?>
                    <span class="skins-nametag">"<?= View::e((string) $equipped['weapon_nametag']) ?>"</span>
                <?php endif; ?>
                <div class="skins-card-tags">
                    <?php if ($team): ?>
                        <?= $teamBadge((int) $team) ?>
                    <?php elseif (!$forcedTeam): ?>
                        <span class="skins-tag-muted"><?= View::e(Lang::t('skins.not_set')) ?></span>
                    <?php endif; ?>
                    <?php if ($forcedTeam): ?>
                        <span class="skins-tag-muted"><?= View::e(WP::teamShort($forcedTeam)) ?><?= View::e(Lang::t('skins.only_suffix')) ?></span>
                    <?php endif; ?>
                </div>
            </button>
        <?php endforeach; endforeach; ?>

        <?php
        // Only real per-team knife rows (skip legacy/empty team-0 rows that
        // would render a stray extra knife card). If none, a single default.
        $knifeRowsOv = [];
        foreach ($selectedKnives as $tId => $kRow) {
            if (!empty($kRow['knife']) && (int) $tId > 0) {
                $knifeRowsOv[(int) $tId] = $kRow;
            }
        }
        $displayKnives = empty($knifeRowsOv) ? [WP::TEAM_BOTH => null] : $knifeRowsOv;
        foreach ($displayKnives as $tId => $knifeEquippedRow):
            $knifeEquippedName = $knifeEquippedRow['knife'] ?? null;
            $knifeTeam = $knifeEquippedRow['weapon_team'] ?? null;
            $knifeDisplay = $knifes[0];
            $knifeDisplayKey = 0;
            foreach ($knifes as $kKey => $k) {
                if ($knifeEquippedName && $k['weapon_name'] === $knifeEquippedName) {
                    $knifeDisplay = $k;
                    $knifeDisplayKey = $kKey;
                    break;
                }
            }
            $knifePaintEquipped = $selectedSkins[$knifeDisplayKey][$tId] ?? ($selectedSkins[$knifeDisplayKey][WP::TEAM_BOTH] ?? null);
            $knifeImage = ($knifePaintEquipped && isset($skins[$knifeDisplayKey][$knifePaintEquipped['weapon_paint_id']]))
                ? $skins[$knifeDisplayKey][$knifePaintEquipped['weapon_paint_id']]['image_url']
                : $knifeDisplay['image_url'];
            $knifePaintName = ($knifePaintEquipped && isset($skins[$knifeDisplayKey][$knifePaintEquipped['weapon_paint_id']]))
                ? $skins[$knifeDisplayKey][$knifePaintEquipped['weapon_paint_id']]['paint_name']
                : $knifeDisplay['paint_name'];
            $knifeColor = ($knifePaintEquipped && isset($skins[$knifeDisplayKey][$knifePaintEquipped['weapon_paint_id']]['color']))
                ? (string) $skins[$knifeDisplayKey][$knifePaintEquipped['weapon_paint_id']]['color']
                : '';
        ?>
        <button type="button" class="skins-card<?= $knifeEquippedName ? ' is-equipped' : '' ?>" data-weapon-nav data-defindex="knife-<?= (int) $knifeDisplayKey ?>"<?= $knifeColor !== '' ? ' data-rarity style="--rarity:' . View::e($knifeColor) . '"' : '' ?>>
            <div class="skins-card-img">
                <img src="<?= View::e($knifeImage) ?>" alt="" loading="lazy" decoding="async">
            </div>
            <p class="skins-card-name"><?= View::e(Lang::t('skins.knife_label')) ?></p>
            <p class="skins-card-sub"><?= View::e(trim((string) $knifePaintName)) ?></p>
            <?php if ($knifePaintEquipped && !empty($knifePaintEquipped['weapon_nametag'])): ?>
                <span class="skins-nametag">"<?= View::e((string) $knifePaintEquipped['weapon_nametag']) ?>"</span>
            <?php endif; ?>
            <div class="skins-card-tags">
                <?php if ($knifeTeam): ?>
                    <?= $teamBadge((int) $knifeTeam) ?>
                <?php else: ?>
                    <span class="skins-tag-muted"><?= View::e(Lang::t('skins.not_set')) ?></span>
                <?php endif; ?>
            </div>
        </button>
        <?php endforeach; ?>

        <?php
        $gloveRowsOv = [];
        foreach ($selectedGloves as $tId => $gRow) {
            if (!empty($gRow) && (int) $tId > 0) {
                $gloveRowsOv[(int) $tId] = $gRow;
            }
        }
        $displayGloves = empty($gloveRowsOv) ? [WP::TEAM_BOTH => null] : $gloveRowsOv;
        foreach ($displayGloves as $tId => $gloveEquippedRow):
            $gloveEquippedDefindex = isset($gloveEquippedRow['weapon_defindex']) ? (int) $gloveEquippedRow['weapon_defindex'] : null;
            $gloveTeam = $gloveEquippedRow['weapon_team'] ?? null;
            $gloveDisplayKey = $gloveEquippedDefindex !== null && isset($gloves[$gloveEquippedDefindex]) ? $gloveEquippedDefindex : 0;
            $gloveDisplay = $gloves[$gloveDisplayKey];
            $glovePaintEquipped = $selectedSkins[$gloveDisplayKey][$tId] ?? ($selectedSkins[$gloveDisplayKey][WP::TEAM_BOTH] ?? null);
            $gloveImage = ($glovePaintEquipped && isset($gloveSkinsAll[$gloveDisplayKey][$glovePaintEquipped['weapon_paint_id']]))
                ? $gloveSkinsAll[$gloveDisplayKey][$glovePaintEquipped['weapon_paint_id']]['image_url']
                : $gloveDisplay['image_url'];
            $glovePaintName = ($glovePaintEquipped && isset($gloveSkinsAll[$gloveDisplayKey][$glovePaintEquipped['weapon_paint_id']]))
                ? $gloveSkinsAll[$gloveDisplayKey][$glovePaintEquipped['weapon_paint_id']]['paint_name']
                : $gloveDisplay['paint_name'];
            $gloveColor = ($glovePaintEquipped && isset($gloveSkinsAll[$gloveDisplayKey][$glovePaintEquipped['weapon_paint_id']]['color']))
                ? (string) $gloveSkinsAll[$gloveDisplayKey][$glovePaintEquipped['weapon_paint_id']]['color']
                : '';
        ?>
        <button type="button" class="skins-card<?= !empty($gloveEquippedRow) ? ' is-equipped' : '' ?>" data-weapon-nav data-defindex="glove-<?= (int) $gloveDisplayKey ?>"<?= $gloveColor !== '' ? ' data-rarity style="--rarity:' . View::e($gloveColor) . '"' : '' ?>>
            <div class="skins-card-img">
                <?php if ($gloveImage): ?>
                    <img src="<?= View::e($gloveImage) ?>" alt="" loading="lazy" decoding="async">
                <?php endif; ?>
            </div>
            <p class="skins-card-name"><?= View::e(Lang::t('skins.gloves_label')) ?></p>
            <p class="skins-card-sub"><?= View::e(trim((string) $glovePaintName)) ?></p>
            <div class="skins-card-tags">
                <?php if ($gloveTeam): ?>
                    <?= $teamBadge((int) $gloveTeam) ?>
                <?php else: ?>
                    <span class="skins-tag-muted"><?= View::e(Lang::t('skins.not_set')) ?></span>
                <?php endif; ?>
            </div>
        </button>
        <?php endforeach; ?>

        <?php
        /* Agents (CT + T) summary cards in the overview — click to open the
           agents editor. Shows the equipped agent for each side, falling back
           to the side's default (first) agent entry when none is equipped. */
        $overviewAgents = [
            ['team' => WP::TEAM_CT, 'list' => $agentsCT, 'sel' => $selectedAgents['ct'] ?? null, 'label' => Lang::t('skins.nav_agents_ct')],
            ['team' => WP::TEAM_T,  'list' => $agentsT,  'sel' => $selectedAgents['t'] ?? null,  'label' => Lang::t('skins.nav_agents_t')],
        ];
        foreach ($overviewAgents as $oa):
            if (empty($oa['list'])) {
                continue;
            }
            $chosen = null;
            foreach ($oa['list'] as $ag) {
                if (!empty($oa['sel']) && ($ag['model'] ?? null) === $oa['sel']) { $chosen = $ag; break; }
            }
            $hasAgent = $chosen !== null;
            if ($chosen === null) {
                $chosen = $oa['list'][0];
            }
            $agentImg  = (string) ($chosen['image'] ?? '');
            $agentName = (string) ($chosen['agent_name'] ?? '');
            $agentColorOv = $hasAgent ? (string) ($chosen['color'] ?? '') : '';
        ?>
            <button type="button" class="skins-card skins-agent<?= $hasAgent ? ' is-equipped' : '' ?>" data-weapon-nav data-defindex="agents"<?= $agentColorOv !== '' ? ' data-rarity style="--rarity:' . View::e($agentColorOv) . '"' : '' ?>>
                <?php if ($hasAgent): ?>
                    <div class="skins-card-badges"><?= $teamBadge((int) $oa['team']) ?></div>
                <?php endif; ?>
                <div class="skins-card-img">
                    <?php if ($agentImg !== ''): ?><img src="<?= View::e($agentImg) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                </div>
                <p class="skins-card-name"><?= View::e($oa['label']) ?></p>
                <p class="skins-card-sub"><?= $hasAgent ? View::e($agentName) : View::e(Lang::t('skins.not_set')) ?></p>
            </button>
        <?php endforeach; ?>

        <?php
        /* Music kit + Pin/coin summary cards — ONE per team that has one
           equipped (a player can run different kits/pins on T vs CT). If none
           is equipped at all, a single "not set" card is shown. Clicking opens
           the matching tab. */
        $musicByTeam = [];
        foreach ($selectedMusic as $mTeam => $mId) {
            if ((int) $mId > 0) {
                foreach ($allMusic as $m) {
                    if ((int) ($m['id'] ?? 0) === (int) $mId) { $musicByTeam[(int) $mTeam] = $m; break; }
                }
            }
        }
        if (empty($musicByTeam)) { $musicByTeam = [0 => null]; }
        foreach ($musicByTeam as $mTeam => $musicRow):
        ?>
        <button type="button" class="skins-card skins-agent<?= $musicRow ? ' is-equipped' : '' ?>" data-weapon-nav data-defindex="music">
            <?php if ($musicRow && (int) $mTeam > 0): ?><div class="skins-card-badges"><?= $teamBadge((int) $mTeam) ?></div><?php endif; ?>
            <div class="skins-card-img">
                <?php if ($musicRow && !empty($musicRow['image'])): ?><img src="<?= View::e($musicRow['image']) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
            </div>
            <p class="skins-card-name"><?= View::e(Lang::t('skins.nav_music_kits')) ?></p>
            <p class="skins-card-sub"><?= $musicRow ? View::e((string) $musicRow['name']) : View::e(Lang::t('skins.not_set')) ?></p>
        </button>
        <?php endforeach; ?>

        <?php
        $pinsByTeam = [];
        foreach ($selectedPins as $pTeam => $pId) {
            if ((int) $pId > 0) {
                foreach ($allCollectibles as $c) {
                    if ((int) ($c['id'] ?? 0) === (int) $pId) { $pinsByTeam[(int) $pTeam] = $c; break; }
                }
            }
        }
        if (empty($pinsByTeam)) { $pinsByTeam = [0 => null]; }
        foreach ($pinsByTeam as $pTeam => $pinRow):
        ?>
        <button type="button" class="skins-card skins-agent<?= $pinRow ? ' is-equipped' : '' ?>" data-weapon-nav data-defindex="pins">
            <?php if ($pinRow && (int) $pTeam > 0): ?><div class="skins-card-badges"><?= $teamBadge((int) $pTeam) ?></div><?php endif; ?>
            <div class="skins-card-img">
                <?php if ($pinRow && !empty($pinRow['image'])): ?><img src="<?= View::e($pinRow['image']) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
            </div>
            <p class="skins-card-name"><?= View::e(Lang::t('skins.nav_pins_coins')) ?></p>
            <p class="skins-card-sub"><?= $pinRow ? View::e((string) $pinRow['name']) : View::e(Lang::t('skins.not_set')) ?></p>
        </button>
        <?php endforeach; ?>
    </div>
</section>

<?php
/* ===== Your loadout: ONLY the items the player currently has equipped =====
   Separate from the all-weapons overview above. Shown when the "Your loadout"
   sidebar button is active (data-defindex="loadout"). Each card is clickable
   and jumps to that item's editor, just like the overview cards. */
$loadoutCards = [];

// Weapons: show every EQUIPPED team-row (a weapon can run a different skin on
// T vs CT), or the weapon's default when nothing is equipped. Forced-team
// weapons collapse to their single forced-team row.
foreach ($weapons as $defindex => $default) {
    $forced = WP::getForcedTeam((int) $defindex);
    $allRows = $selectedSkins[$defindex] ?? [];

    // Decide which equipped rows to show as cards. Per-team rows (T/CT) are the
    // truth; a leftover "Both/ALL" row is stale when per-team rows exist, so
    // ALL shows ONLY when it is the sole equipped row.
    $rowsToShow = [];
    if ($forced) {
        if (isset($allRows[$forced])) { $rowsToShow[] = $allRows[$forced]; }
    } else {
        $perTeam = [];
        foreach ($allRows as $tId => $r) {
            if ((int) $tId === WP::TEAM_T || (int) $tId === WP::TEAM_CT) { $perTeam[] = $r; }
        }
        if (!empty($perTeam)) {
            $rowsToShow = $perTeam;
        } elseif (isset($allRows[WP::TEAM_BOTH])) {
            $rowsToShow[] = $allRows[WP::TEAM_BOTH];
        }
    }

    if (empty($rowsToShow)) {
        // Nothing equipped: a single default (unset) card.
        $loadoutCards[] = [
            'nav' => (string) $defindex, 'image' => $default['image_url'],
            'name' => $default['display_name'], 'sub' => trim((string) $default['paint_name']),
            'team' => 0, 'wear' => '', 'equipped' => false, 'overlay' => '',
        ];
        continue;
    }
    foreach ($rowsToShow as $equipped) {
        $paint = $skins[$defindex][$equipped['weapon_paint_id']] ?? $default;
        $loadoutCards[] = [
            'nav'   => (string) $defindex,
            'image' => $paint['image_url'],
            'name'  => $default['display_name'],
            'sub'   => trim((string) $paint['paint_name']),
            'team'  => (int) ($equipped['weapon_team'] ?? 0),
            'wear'  => isset($equipped['weapon_wear']) ? WP::wearLabelShort((float) $equipped['weapon_wear']) : '',
            'equipped' => true,
            'overlay' => $stickerOverlay($equipped['stickers'] ?? [], $equipped['keychain'] ?? []),
            'nametag' => (string) ($equipped['weapon_nametag'] ?? ''),
        ];
    }
}

// Knife: one card per team that actually has a knife equipped. If NOTHING is
// equipped, show a single default knife card. Rows without a real team
// (legacy/empty) are skipped so no stray "unset" knife card appears.
$knifeRows = [];
foreach ($selectedKnives as $teamId => $kRow) {
    if (!empty($kRow['knife']) && (int) $teamId > 0) {
        $knifeRows[(int) $teamId] = $kRow;
    }
}
if (empty($knifeRows)) {
    // Nothing equipped -> a single default placeholder card.
    $loadoutCards[] = [
        'nav' => 'knife-0', 'image' => $knifes[0]['image_url'] ?? '',
        'name' => Lang::t('skins.knife_label'), 'sub' => trim((string) ($knifes[0]['paint_name'] ?? '')),
        'team' => 0, 'wear' => '', 'equipped' => false,
    ];
} else {
    foreach ($knifeRows as $teamId => $kRow) {
        $kName = $kRow['knife'];
        $kKey = 0;
        foreach ($knifes as $idx => $k) {
            if ($k['weapon_name'] === $kName) { $kKey = $idx; break; }
        }
        $kPaint = $selectedSkins[$kKey][$teamId] ?? ($selectedSkins[$kKey][WP::TEAM_BOTH] ?? null);
        $kImg = ($kPaint && isset($skins[$kKey][$kPaint['weapon_paint_id']])) ? $skins[$kKey][$kPaint['weapon_paint_id']]['image_url'] : ($knifes[$kKey]['image_url'] ?? '');
        $kSub = ($kPaint && isset($skins[$kKey][$kPaint['weapon_paint_id']])) ? $skins[$kKey][$kPaint['weapon_paint_id']]['paint_name'] : ($knifes[$kKey]['paint_name'] ?? '');
        $loadoutCards[] = [
            'nav'   => 'knife-' . (int) $kKey,
            'image' => $kImg,
            'name'  => Lang::t('skins.knife_label'),
            'sub'   => trim((string) $kSub),
            'team'  => (int) $teamId,
            'wear'  => '',
            'equipped' => true,
            'nametag' => $kPaint ? (string) ($kPaint['weapon_nametag'] ?? '') : '',
        ];
    }
}

// Gloves: one card per team that actually has gloves equipped, else a single
// default placeholder. Rows without a real team are skipped.
$gloveRows = [];
foreach ($selectedGloves as $teamId => $gRow) {
    if (!empty($gRow) && (int) $teamId > 0) {
        $gloveRows[(int) $teamId] = $gRow;
    }
}
if (empty($gloveRows)) {
    $loadoutCards[] = [
        'nav' => 'glove-0', 'image' => $gloves[0]['image_url'] ?? '',
        'name' => Lang::t('skins.gloves_label'), 'sub' => trim((string) ($gloves[0]['paint_name'] ?? '')),
        'team' => 0, 'wear' => '', 'equipped' => false,
    ];
} else {
    foreach ($gloveRows as $teamId => $gRow) {
        $gKey = (isset($gRow['weapon_defindex']) && isset($gloves[(int) $gRow['weapon_defindex']])) ? (int) $gRow['weapon_defindex'] : 0;
        $gPaint = $selectedSkins[$gKey][$teamId] ?? ($selectedSkins[$gKey][WP::TEAM_BOTH] ?? null);
        $gImg = ($gPaint && isset($gloveSkinsAll[$gKey][$gPaint['weapon_paint_id']])) ? $gloveSkinsAll[$gKey][$gPaint['weapon_paint_id']]['image_url'] : ($gloves[$gKey]['image_url'] ?? '');
        $gSub = ($gPaint && isset($gloveSkinsAll[$gKey][$gPaint['weapon_paint_id']])) ? $gloveSkinsAll[$gKey][$gPaint['weapon_paint_id']]['paint_name'] : ($gloves[$gKey]['paint_name'] ?? '');
        $loadoutCards[] = [
            'nav'   => 'glove-' . (int) $gKey,
            'image' => $gImg,
            'name'  => Lang::t('skins.gloves_label'),
            'sub'   => trim((string) $gSub),
            'team'  => (int) $teamId,
            'wear'  => '',
            'equipped' => true,
        ];
    }
}

// Agents (CT + T): only sides that have an agent equipped.
$agentPairs = [
    ['team' => WP::TEAM_CT, 'list' => $agentsCT, 'sel' => $selectedAgents['ct'] ?? null, 'label' => Lang::t('skins.nav_agents_ct')],
    ['team' => WP::TEAM_T,  'list' => $agentsT,  'sel' => $selectedAgents['t'] ?? null,  'label' => Lang::t('skins.nav_agents_t')],
];
foreach ($agentPairs as $ap) {
    if (empty($ap['sel'])) {
        continue;
    }
    $chosen = null;
    foreach ($ap['list'] as $ag) {
        if (($ag['model'] ?? null) === $ap['sel']) { $chosen = $ag; break; }
    }
    if ($chosen === null) {
        continue;
    }
    $loadoutCards[] = [
        'nav'   => 'agents',
        'image' => (string) ($chosen['image'] ?? ''),
        'name'  => $ap['label'],
        'sub'   => (string) ($chosen['agent_name'] ?? ''),
        'team'  => (int) $ap['team'],
        'wear'  => '',
        'equipped' => true,
    ];
}

// Music kit: one card per team that has a kit equipped (T/CT can differ).
foreach ($selectedMusic as $mTeam => $mId) {
    if ((int) $mId <= 0) {
        continue;
    }
    foreach ($allMusic as $m) {
        if ((int) ($m['id'] ?? 0) === (int) $mId) {
            $loadoutCards[] = [
                'nav' => 'music', 'image' => (string) ($m['image'] ?? ''),
                'name' => Lang::t('skins.nav_music_kits'), 'sub' => (string) ($m['name'] ?? ''),
                'team' => (int) $mTeam, 'wear' => '', 'equipped' => true,
            ];
            break;
        }
    }
}

// Pin / coin: one card per team that has one equipped.
foreach ($selectedPins as $pTeam => $pId) {
    if ((int) $pId <= 0) {
        continue;
    }
    foreach ($allCollectibles as $c) {
        if ((int) ($c['id'] ?? 0) === (int) $pId) {
            $loadoutCards[] = [
                'nav' => 'pins', 'image' => (string) ($c['image'] ?? ''),
                'name' => Lang::t('skins.nav_pins_coins'), 'sub' => (string) ($c['name'] ?? ''),
                'team' => (int) $pTeam, 'wear' => '', 'equipped' => true,
            ];
            break;
        }
    }
}
?>
<section class="skins-section" data-weapon-section data-defindex="loadout">
    <p class="skins-section-desc"><?= View::e(Lang::t('skins.loadout_description')) ?></p>
    <?php if (empty($loadoutCards)): ?>
        <div class="skins-empty-loadout"><?= View::e(Lang::t('skins.loadout_empty')) ?></div>
    <?php else: ?>
        <div class="skins-grid">
            <?php foreach ($loadoutCards as $lc): ?>
                <button type="button" class="skins-card<?= !empty($lc['equipped']) ? ' is-equipped' : '' ?>" data-weapon-nav data-defindex="<?= View::e($lc['nav']) ?>">
                    <div class="skins-card-img">
                        <?php if (!empty($lc['image'])): ?>
                            <img src="<?= View::e($lc['image']) ?>" alt="" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <?= $lc['overlay'] ?? '' ?>
                        <?php if ($lc['wear'] !== ''): ?>
                            <span class="skins-wear"><?= View::e($lc['wear']) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="skins-card-name"><?= View::e($lc['name']) ?></p>
                    <p class="skins-card-sub"><?= View::e($lc['sub']) ?></p>
                    <?php if (!empty($lc['nametag'])): ?>
                        <span class="skins-nametag">"<?= View::e((string) $lc['nametag']) ?>"</span>
                    <?php endif; ?>
                    <div class="skins-card-tags">
                        <?php if (!empty($lc['equipped']) && $lc['team']): ?>
                            <?= $teamBadge((int) $lc['team']) ?>
                        <?php elseif (empty($lc['equipped'])): ?>
                            <span class="skins-tag-muted"><?= View::e(Lang::t('skins.not_set')) ?></span>
                        <?php endif; ?>
                    </div>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Weapons: each weapon's full paint grid is rendered LAZILY by skins.js the
     first time the weapon is opened. Rendering all ~2000 skins up front builds a
     huge DOM that crashes low-memory mobile browsers, so here we only emit the
     empty section shells + a JSON catalog the client builds cards from. -->
<?php
$weaponCatalog = [];
foreach ($weapons as $defindex => $default) {
    $forcedTeam = WP::getForcedTeam((int) $defindex);

    // Which equipped rows count for this weapon's grid:
    //  - forced-team weapon: only the forced team's row;
    //  - if ANY per-team row (T or CT) exists, those are the truth and a leftover
    //    "Both/ALL" row from the past is STALE -> ignored;
    //  - "Both/ALL" is shown ONLY when it is the sole row (no per-team rows).
    $rows = $selectedSkins[$defindex] ?? [];
    $effRows = [];
    if ($forcedTeam) {
        if (isset($rows[$forcedTeam])) { $effRows[(int) $forcedTeam] = $rows[$forcedTeam]; }
    } else {
        $perTeam = [];
        foreach ($rows as $tId => $eq) {
            if ((int) $tId === WP::TEAM_T || (int) $tId === WP::TEAM_CT) { $perTeam[(int) $tId] = $eq; }
        }
        if (!empty($perTeam)) {
            $effRows = $perTeam;                       // per-team wins; drop stale ALL
        } elseif (isset($rows[WP::TEAM_BOTH])) {
            $effRows[WP::TEAM_BOTH] = $rows[WP::TEAM_BOTH];
        }
    }

    $paints = [];
    foreach (($skins[$defindex] ?? []) as $paintId => $paint) {
        $equippedTeams = [];
        foreach ($effRows as $tId => $eq) {
            if ($eq['weapon_paint_id'] === $paintId) {
                $equippedTeams[] = (int) $tId;
            }
        }
        $firstEq = $equippedTeams ? $selectedSkins[$defindex][$equippedTeams[0]] : null;
        $paints[] = [
            'paint'     => (int) $paintId,
            'name'      => $default['display_name'],
            'paintName' => trim(str_replace($default['display_name'], '', (string) $paint['paint_name']), ' |'),
            'fullName'  => trim((string) $paint['paint_name']),
            'image'     => $paint['image_url'],
            'color'     => (string) ($paint['color'] ?? ''),
            'teams'     => $equippedTeams,
            'wear'      => $firstEq ? (float) $firstEq['weapon_wear'] : 0,
            'wearShort' => $firstEq ? WP::wearLabelShort((float) $firstEq['weapon_wear']) : '',
            'seed'      => $firstEq ? (int) $firstEq['weapon_seed'] : 0,
            'stattrak'  => $firstEq ? (int) ($firstEq['weapon_stattrak'] ?? 0) : 0,
            'stCount'   => $firstEq ? (int) ($firstEq['weapon_stattrak_count'] ?? 0) : 0,
            'nametag'   => $firstEq ? (string) ($firstEq['weapon_nametag'] ?? '') : '',
            'stickers'  => $firstEq ? array_values($firstEq['stickers'] ?? []) : [],
            'keychain'  => $firstEq ? ($firstEq['keychain'] ?? ['id' => 0, 'x' => 0, 'y' => 0]) : ['id' => 0, 'x' => 0, 'y' => 0],
        ];
    }
    $weaponCatalog[(string) $defindex] = [
        'display'    => $default['display_name'],
        'forcedTeam' => $forcedTeam,
        'paints'     => $paints,
    ];
}
?>
<?php foreach ($weapons as $defindex => $default):
    $forcedTeam = WP::getForcedTeam((int) $defindex);
?>
    <section class="skins-section" data-weapon-section data-defindex="<?= (int) $defindex ?>">
        <?php if ($forcedTeam): ?>
            <div class="skins-forced-note"><?= View::e(Lang::t('skins.forced_team_note', ['team' => WP::teamLabel($forcedTeam)])) ?></div>
        <?php endif; ?>
        <div class="skins-grid" data-lazy-grid data-defindex="<?= (int) $defindex ?>"></div>
    </section>
<?php endforeach; ?>
<script id="weaponCatalog" type="application/json"><?= json_encode($weaponCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<!-- Knives -->
<?php foreach ($knifes as $knifeDefindex => $knifeType):
    $equippedTeamsForThisKnifeType = [];
    foreach ($selectedKnives as $tId => $kRow) {
        if ($kRow['knife'] === $knifeType['weapon_name']) {
            $equippedTeamsForThisKnifeType[] = $tId;
        }
    }
?>
    <section class="skins-section" data-weapon-section data-defindex="knife-<?= (int) $knifeDefindex ?>">
        <?php if ($knifeDefindex === 0 || !isset($skins[$knifeDefindex])): ?>
            <div class="skins-grid">
                <button type="button" class="skins-card<?= !empty($equippedTeamsForThisKnifeType) ? ' is-equipped' : '' ?>"
                    data-knife-card
                    data-defindex="<?= (int) $knifeDefindex ?>"
                    data-weapon-name="<?= View::e(Lang::t('skins.knife_label')) ?>"
                    data-paint-name="<?= View::e($knifeType['paint_name']) ?>"
                    data-image="<?= View::e($knifeType['image_url']) ?>"
                    data-search="<?= View::e(strtolower($knifeType['paint_name'] . ' knife default')) ?>"
                    data-team="<?= !empty($equippedTeamsForThisKnifeType) ? (int) $equippedTeamsForThisKnifeType[0] : WP::TEAM_BOTH ?>">
                    <?php if (!empty($equippedTeamsForThisKnifeType)): ?>
                        <div class="skins-card-badges">
                            <?php foreach ($equippedTeamsForThisKnifeType as $et): ?><?= $teamBadge((int) $et) ?><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="skins-card-img"><img src="<?= View::e($knifeType['image_url']) ?>" alt="" loading="lazy" decoding="async"></div>
                    <p class="skins-card-name"><?= View::e($knifeType['paint_name']) ?></p>
                </button>
            </div>
        <?php else: ?>
            <div class="skins-grid">
                <?php foreach ($skins[$knifeDefindex] as $paintId => $paint):
                    $equippedTeamsForThisPaint = [];
                    if (isset($selectedSkins[$knifeDefindex])) {
                        foreach ($selectedSkins[$knifeDefindex] as $tId => $eq) {
                            if ($eq['weapon_paint_id'] === $paintId && in_array($tId, $equippedTeamsForThisKnifeType)) {
                                $equippedTeamsForThisPaint[] = $tId;
                            }
                        }
                    }
                    $isCurrentPaint = !empty($equippedTeamsForThisPaint);
                    $firstEq = $isCurrentPaint ? $selectedSkins[$knifeDefindex][$equippedTeamsForThisPaint[0]] : null;
                    $dataTeam = $isCurrentPaint ? $equippedTeamsForThisPaint[0] : (!empty($equippedTeamsForThisKnifeType) ? $equippedTeamsForThisKnifeType[0] : WP::TEAM_BOTH);
                ?>
                    <?php $kColor = (string) ($paint['color'] ?? ''); ?>
                    <button type="button" class="skins-card<?= $isCurrentPaint ? ' is-equipped' : '' ?>"<?= $kColor !== '' ? ' data-rarity style="--rarity:' . View::e($kColor) . '"' : '' ?>
                        data-knife-card
                        data-defindex="<?= (int) $knifeDefindex ?>"
                        data-paintid="<?= (int) $paintId ?>"
                        data-weapon-name="<?= View::e($knifeType['paint_name']) ?>"
                        data-paint-name="<?= View::e(trim((string) $paint['paint_name'])) ?>"
                        data-image="<?= View::e($paint['image_url']) ?>"
                        data-search="<?= View::e(strtolower($knifeType['paint_name'] . ' ' . $paint['paint_name'])) ?>"
                        data-wear="<?= $isCurrentPaint ? View::e((string) $firstEq['weapon_wear']) : '0' ?>"
                        data-seed="<?= $isCurrentPaint ? View::e((string) $firstEq['weapon_seed']) : '0' ?>"
                        data-stattrak="<?= $isCurrentPaint ? View::e((string) ($firstEq['weapon_stattrak'] ?? 0)) : '0' ?>"
                        data-stattrak-count="<?= $isCurrentPaint ? View::e((string) ($firstEq['weapon_stattrak_count'] ?? 0)) : '0' ?>"
                        data-nametag="<?= $isCurrentPaint ? View::e((string) ($firstEq['weapon_nametag'] ?? '')) : '' ?>"
                        data-team="<?= (int) $dataTeam ?>">
                        <?php if ($isCurrentPaint): ?>
                            <div class="skins-card-badges">
                                <?php foreach ($equippedTeamsForThisPaint as $et): ?><?= $teamBadge((int) $et) ?><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="skins-card-img">
                            <img src="<?= View::e($paint['image_url']) ?>" alt="" loading="lazy" decoding="async">
                            <?php if ($isCurrentPaint && $firstEq): ?>
                                <span class="skins-wear"><?= View::e(WP::wearLabelShort((float) $firstEq['weapon_wear'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="skins-card-name"><?= View::e($knifeType['paint_name']) ?></p>
                        <p class="skins-card-sub"><?= View::e(trim(str_replace($knifeType['paint_name'], '', $paint['paint_name']), " |")) ?></p>
                        <?php if ($isCurrentPaint && $firstEq && !empty($firstEq['weapon_nametag'])): ?>
                            <span class="skins-nametag">"<?= View::e((string) $firstEq['weapon_nametag']) ?>"</span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<!-- Gloves -->
<?php foreach ($gloves as $gloveDefindex => $gloveType):
    $equippedTeamsForThisGloveType = [];
    foreach ($selectedGloves as $tId => $gRow) {
        if ((int) $gRow['weapon_defindex'] === $gloveDefindex) {
            $equippedTeamsForThisGloveType[] = $tId;
        }
    }
?>
    <section class="skins-section" data-weapon-section data-defindex="glove-<?= (int) $gloveDefindex ?>">
        <?php if ($gloveDefindex === 0 || !isset($gloveSkinsAll[$gloveDefindex])): ?>
            <div class="skins-grid">
                <button type="button" class="skins-card<?= !empty($equippedTeamsForThisGloveType) ? ' is-equipped' : '' ?>"
                    data-glove-card
                    data-defindex="<?= (int) $gloveDefindex ?>"
                    data-weapon-name="<?= View::e(Lang::t('skins.gloves_label')) ?>"
                    data-paint-name="<?= View::e($gloveType['paint_name']) ?>"
                    data-image=""
                    data-search="<?= View::e(strtolower($gloveType['paint_name'] . ' gloves default')) ?>"
                    data-team="<?= !empty($equippedTeamsForThisGloveType) ? (int) $equippedTeamsForThisGloveType[0] : WP::TEAM_BOTH ?>">
                    <?php if (!empty($equippedTeamsForThisGloveType)): ?>
                        <div class="skins-card-badges">
                            <?php foreach ($equippedTeamsForThisGloveType as $et): ?><?= $teamBadge((int) $et) ?><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="skins-card-img"></div>
                    <p class="skins-card-name"><?= View::e($gloveType['paint_name']) ?></p>
                </button>
            </div>
        <?php else: ?>
            <div class="skins-grid">
                <?php foreach ($gloveSkinsAll[$gloveDefindex] as $paintId => $paint):
                    $equippedTeamsForThisPaint = [];
                    if (isset($selectedSkins[$gloveDefindex])) {
                        foreach ($selectedSkins[$gloveDefindex] as $tId => $eq) {
                            if ($eq['weapon_paint_id'] === $paintId && in_array($tId, $equippedTeamsForThisGloveType)) {
                                $equippedTeamsForThisPaint[] = $tId;
                            }
                        }
                    }
                    $isCurrentGlovePaint = !empty($equippedTeamsForThisPaint);
                    $firstEq = $isCurrentGlovePaint ? $selectedSkins[$gloveDefindex][$equippedTeamsForThisPaint[0]] : null;
                    $dataTeam = $isCurrentGlovePaint ? $equippedTeamsForThisPaint[0] : (!empty($equippedTeamsForThisGloveType) ? $equippedTeamsForThisGloveType[0] : WP::TEAM_BOTH);
                ?>
                    <?php $gColor = (string) ($paint['color'] ?? ''); ?>
                    <button type="button" class="skins-card<?= $isCurrentGlovePaint ? ' is-equipped' : '' ?>"<?= $gColor !== '' ? ' data-rarity style="--rarity:' . View::e($gColor) . '"' : '' ?>
                        data-glove-card
                        data-defindex="<?= (int) $gloveDefindex ?>"
                        data-paintid="<?= (int) $paintId ?>"
                        data-weapon-name="<?= View::e($gloveType['paint_name']) ?>"
                        data-paint-name="<?= View::e(trim((string) $paint['paint_name'])) ?>"
                        data-image="<?= View::e($paint['image_url']) ?>"
                        data-search="<?= View::e(strtolower($gloveType['paint_name'] . ' ' . $paint['paint_name'])) ?>"
                        data-wear="<?= $isCurrentGlovePaint ? View::e((string) $firstEq['weapon_wear']) : '0' ?>"
                        data-seed="<?= $isCurrentGlovePaint ? View::e((string) $firstEq['weapon_seed']) : '0' ?>"
                        data-team="<?= (int) $dataTeam ?>">
                        <?php if ($isCurrentGlovePaint): ?>
                            <div class="skins-card-badges">
                                <?php foreach ($equippedTeamsForThisPaint as $et): ?><?= $teamBadge((int) $et) ?><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="skins-card-img">
                            <img src="<?= View::e($paint['image_url']) ?>" alt="" loading="lazy" decoding="async">
                            <?php if ($isCurrentGlovePaint && $firstEq): ?>
                                <span class="skins-wear"><?= View::e(WP::wearLabelShort((float) $firstEq['weapon_wear'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="skins-card-name"><?= View::e($gloveType['paint_name']) ?></p>
                        <p class="skins-card-sub"><?= View::e(trim(str_replace($gloveType['paint_name'], '', $paint['paint_name']), " |")) ?></p>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<!-- Agents (CT + T) -->
<section class="skins-section" data-weapon-section data-defindex="agents">
    <p class="skins-section-desc"><?= View::e(Lang::t('skins.agents_description')) ?></p>
    <div class="skins-agents-wrap">
        <div class="skins-agents-col">
            <h3 class="skins-agents-title"><?= View::e(Lang::t('skins.nav_agents_ct')) ?></h3>
            <div class="skins-grid">
                <?php foreach ($agentsCT as $agent):
                    $isEquippedCT = ($selectedAgents['ct'] === $agent['model']) || (empty($selectedAgents['ct']) && (($agent['model'] ?? 'null') === 'null' || empty($agent['model'])));
                ?>
                    <?php $aColorCT = (string) ($agent['color'] ?? ''); ?>
                    <button type="button" class="skins-card skins-agent<?= $isEquippedCT ? ' is-equipped' : '' ?>"<?= $aColorCT !== '' ? ' data-rarity style="--rarity:' . View::e($aColorCT) . '"' : '' ?>
                        data-agent-card
                        data-team="3"
                        data-model="<?= View::e($agent['model']) ?>"
                        data-agent-name="<?= View::e($agent['agent_name']) ?>"
                        data-image="<?= View::e($agent['image']) ?>"
                        data-search="<?= View::e(strtolower('agent ct ' . $agent['agent_name'])) ?>">
                        <?php if ($isEquippedCT): ?>
                            <div class="skins-card-badges"><?= $teamBadge(WP::TEAM_CT) ?></div>
                        <?php endif; ?>
                        <div class="skins-card-img">
                            <?php if (!empty($agent['image'])): ?><img src="<?= View::e($agent['image']) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                        </div>
                        <p class="skins-card-name"><?= View::e($agent['agent_name']) ?></p>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="skins-agents-col">
            <h3 class="skins-agents-title"><?= View::e(Lang::t('skins.nav_agents_t')) ?></h3>
            <div class="skins-grid">
                <?php foreach ($agentsT as $agent):
                    $isEquippedT = ($selectedAgents['t'] === $agent['model']) || (empty($selectedAgents['t']) && (($agent['model'] ?? 'null') === 'null' || empty($agent['model'])));
                ?>
                    <?php $aColorT = (string) ($agent['color'] ?? ''); ?>
                    <button type="button" class="skins-card skins-agent<?= $isEquippedT ? ' is-equipped' : '' ?>"<?= $aColorT !== '' ? ' data-rarity style="--rarity:' . View::e($aColorT) . '"' : '' ?>
                        data-agent-card
                        data-team="2"
                        data-model="<?= View::e($agent['model']) ?>"
                        data-agent-name="<?= View::e($agent['agent_name']) ?>"
                        data-image="<?= View::e($agent['image']) ?>"
                        data-search="<?= View::e(strtolower('agent t ' . $agent['agent_name'])) ?>">
                        <?php if ($isEquippedT): ?>
                            <div class="skins-card-badges"><?= $teamBadge(WP::TEAM_T) ?></div>
                        <?php endif; ?>
                        <div class="skins-card-img">
                            <?php if (!empty($agent['image'])): ?><img src="<?= View::e($agent['image']) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                        </div>
                        <p class="skins-card-name"><?= View::e($agent['agent_name']) ?></p>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- Music Kits (client-rendered) -->
<section class="skins-section" data-weapon-section data-defindex="music">
    <h3 class="skins-agents-title"><?= View::e(Lang::t('skins.nav_music_kits')) ?></h3>
    <p class="skins-section-desc"><?= View::e(Lang::t('skins.music_description')) ?></p>
    <div id="musicContainer" class="skins-grid"></div>
    <div id="musicPagination" class="skins-pagination"></div>
</section>

<!-- Pins & Coins (client-rendered) -->
<section class="skins-section" data-weapon-section data-defindex="pins">
    <h3 class="skins-agents-title"><?= View::e(Lang::t('skins.nav_pins_coins')) ?></h3>
    <p class="skins-section-desc"><?= View::e(Lang::t('skins.pins_description')) ?></p>
    <div id="pinsContainer" class="skins-grid"></div>
    <div id="pinsPagination" class="skins-pagination"></div>
</section>

<p id="skinsNoResults" class="skins-no-results" style="display:none"><?= View::e(Lang::t('skins.no_results')) ?></p>
