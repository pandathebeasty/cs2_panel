<?php
/**
 * Skins category sidebar. Lists the weapon categories (each collapsible) plus
 * Knives and Gloves groups. Clicking an item shows that weapon's skin section.
 * Carries id #categoryNav so skins.js can swap it on AJAX refresh.
 *
 * @var array $groupedWeapons
 * @var array $knifes
 * @var array $gloves
 */

use App\Core\Lang;
use App\Core\View;
?>
<aside class="skins-sidebar">
    <nav id="categoryNav">
        <button type="button" class="skins-nav-overview" data-weapon-nav data-defindex="loadout">
            <?= View::e(Lang::t('skins.your_loadout')) ?>
        </button>

        <?php foreach ($groupedWeapons as $category => $catWeapons): ?>
            <div class="skins-cat-group" data-category="<?= View::e($category) ?>">
                <button type="button" class="skins-cat-toggle">
                    <span><?= View::e($category) ?></span>
                    <svg class="skins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="skins-cat-list">
                    <?php foreach ($catWeapons as $defindex => $weapon): ?>
                        <button type="button" class="skins-nav-btn" data-weapon-nav data-defindex="<?= (int) $defindex ?>"
                            data-search="<?= View::e(strtolower((string) $weapon['display_name'])) ?>">
                            <span><?= View::e($weapon['display_name']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="skins-cat-group" data-category="<?= View::e(Lang::t('skins.nav_knives')) ?>">
            <button type="button" class="skins-cat-toggle">
                <span><?= View::e(Lang::t('skins.nav_knives')) ?></span>
                <svg class="skins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="skins-cat-list">
                <?php foreach ($knifes as $knifeDefindex => $knifeType): ?>
                    <button type="button" class="skins-nav-btn" data-weapon-nav data-defindex="knife-<?= (int) $knifeDefindex ?>"
                        data-search="<?= View::e(strtolower((string) $knifeType['paint_name'])) ?>">
                        <span><?= View::e($knifeType['paint_name']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="skins-cat-group" data-category="<?= View::e(Lang::t('skins.nav_gloves')) ?>">
            <button type="button" class="skins-cat-toggle">
                <span><?= View::e(Lang::t('skins.nav_gloves')) ?></span>
                <svg class="skins-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="skins-cat-list">
                <?php foreach ($gloves as $gloveDefindex => $gloveType): ?>
                    <button type="button" class="skins-nav-btn" data-weapon-nav data-defindex="glove-<?= (int) $gloveDefindex ?>"
                        data-search="<?= View::e(strtolower((string) $gloveType['paint_name'])) ?>">
                        <span><?= View::e($gloveType['paint_name']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>
</aside>
