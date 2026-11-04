<?php use App\Core\Lang; use App\Core\View;
/** @var array $errors @var array $old @var string $csrf */
$o = fn(string $k, string $d = '') => View::e($old[$k] ?? $d);
$t = static fn(string $k): string => View::e(Lang::t($k));
$title = Lang::t('install.title');
$curLang = Lang::current();
$year = date('Y');
// required marker
$req = '<span class="req">*</span>';
?>
<div class="install-split">
    <!-- Left: brand / hero -->
    <div class="install-hero">
        <div class="install-hero-inner">
            <img src="/assets/img/logo.svg" alt="logo" class="install-logo">
            <h1>CS2 Panel</h1>
            <p><?= $t('install.hero_sub') ?></p>
            <ul class="install-features">
                <li><?= $t('install.hero_compat') ?></li>
            </ul>
        </div>
        <div class="install-credit">
            <img src="/assets/img/logo.svg" alt="" class="footer-logo">
            <span><?= \App\Core\Brand::footer() ?></span>
        </div>
    </div>

    <!-- Right: form -->
    <div class="install-form">
        <div class="install-topbar">
            <div class="dropdown" id="lang-dd">
                <button class="btn btn-icon dropdown-toggle" type="button" aria-label="language"><span class="lang-code"><?= View::e(Lang::AVAILABLE[$curLang]['code']) ?></span></button>
                <div class="dropdown-menu">
                    <?php foreach (Lang::AVAILABLE as $code => $meta): ?>
                        <a href="/lang/<?= View::e($code) ?>" class="dropdown-item <?= $code === $curLang ? 'active' : '' ?>"><span class="lang-code"><?= View::e($meta['code']) ?></span> <?= View::e($meta['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php require BASE_PATH . '/app/Views/partials/effects-control.php'; ?>
            <button class="btn btn-icon theme-toggle" type="button" aria-label="Toggle theme" title="Toggle theme">
                <span class="theme-ico theme-ico-light"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg></span>
                <span class="theme-ico theme-ico-dark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z"/></svg></span>
            </button>
        </div>

        <div class="install-form-scroll">
            <h2><?= $t('install.title') ?></h2>

            <?php foreach ($errors as $e): ?>
                <div class="flash flash-error"><?= View::e($e) ?></div>
            <?php endforeach; ?>

            <form method="post" action="/install" id="install-form" novalidate>
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">

                <h3 class="section-title"><?= $t('install.sec_database') ?></h3>
                <div class="form-row">
                    <div class="field"><label><?= $t('install.host') ?> <?= $req ?></label><input type="text" name="db_host" value="<?= $o('db_host', '127.0.0.1') ?>" required></div>
                    <div class="field"><label><?= $t('install.port') ?></label><input type="number" name="db_port" value="<?= $o('db_port', '3306') ?>"></div>
                </div>
                <div class="form-row">
                    <div class="field"><label><?= $t('install.db_name') ?> <?= $req ?></label><input type="text" name="db_name" value="<?= $o('db_name') ?>" required></div>
                    <div class="field"><label><?= $t('install.sa_prefix') ?></label><input type="text" name="sa_prefix" value="<?= $o('sa_prefix', 'sa_') ?>"></div>
                </div>
                <div class="form-row">
                    <div class="field"><label><?= $t('install.user') ?> <?= $req ?></label><input type="text" name="db_user" value="<?= $o('db_user') ?>" required></div>
                    <div class="field"><label><?= $t('install.password') ?></label><input type="password" name="db_password" value="<?= $o('db_password') ?>"></div>
                </div>

                <h3 class="section-title"><?= $t('install.sec_site') ?></h3>
                <div class="form-row">
                    <div class="field"><label><?= $t('install.site_name') ?></label><input type="text" name="site_name" value="<?= $o('site_name', 'CS2 Panel') ?>"></div>
                    <div class="field"><label><?= $t('install.timezone') ?></label><input type="text" name="timezone" value="<?= $o('timezone', 'UTC') ?>"></div>
                </div>
                <div class="field"><label><?= $t('install.realm') ?> <?= $req ?></label><input type="url" name="realm" placeholder="https://panel.example.com" value="<?= $o('realm', $suggestedRealm ?? '') ?>" required></div>
                <div class="field"><label><?= $t('install.steam_key') ?></label><input type="text" name="steam_api_key" value="<?= $o('steam_api_key') ?>"></div>

                <h3 class="section-title"><?= $t('install.sec_admin') ?></h3>
                <div class="form-row">
                    <div class="field"><label>Root SteamID64 <?= $req ?></label><input type="text" name="root_steamid" placeholder="7656119..." value="<?= $o('root_steamid') ?>" required></div>
                    <div class="field"><label><?= $t('install.display_name') ?></label><input type="text" name="root_name" value="<?= $o('root_name', 'Root') ?>"></div>
                </div>
                <div class="field">
                    <label><?= $t('install.super_admins') ?></label>
                    <input type="text" name="super_admins" value="<?= $o('super_admins') ?>">
                </div>

                <h3 class="section-title"><?= $t('install.sec_integrations') ?></h3>
                <?php
                // Per-integration database; by default it is the panel database.
                $dbBlock = static function (string $xk) use ($old, $o, $t, $req): void {
                    $xon = !empty($old["extdb_{$xk}_enabled"]);
                ?>
                    <div class="extdb-block" style="border-top:1px dashed var(--border);margin-top:12px;padding-top:12px">
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <div class="check" style="margin:0"><input type="checkbox" name="extdb_<?= $xk ?>_enabled" id="extdb_<?= $xk ?>_enabled" class="js-extdb-toggle" data-target="extdb-<?= $xk ?>" <?= $xon ? 'checked' : '' ?>><label for="extdb_<?= $xk ?>_enabled"><?= $t('settings.extdb_own') ?></label></div>
                            <span class="hint" style="margin:0"><?= $t('settings.extdb_hint') ?></span>
                        </div>
                        <div class="extdb-fields" id="extdb-<?= $xk ?>" style="margin-top:10px"<?= $xon ? '' : ' hidden' ?>>
                            <div class="form-row">
                                <div class="field"><label><?= $t('settings.extdb_host') ?></label><input type="text" name="extdb_<?= $xk ?>_host" value="<?= $o("extdb_{$xk}_host") ?>" placeholder="127.0.0.1"></div>
                                <div class="field"><label><?= $t('settings.extdb_port') ?></label><input type="number" name="extdb_<?= $xk ?>_port" value="<?= $o("extdb_{$xk}_port") ?>" placeholder="3306" min="1" max="65535"></div>
                            </div>
                            <div class="form-row">
                                <div class="field"><label><?= $t('settings.extdb_name') ?> <?= $req ?></label><input type="text" name="extdb_<?= $xk ?>_name" value="<?= $o("extdb_{$xk}_name") ?>" data-extdb-required></div>
                                <div class="field"><label><?= $t('settings.extdb_user') ?></label><input type="text" name="extdb_<?= $xk ?>_user" value="<?= $o("extdb_{$xk}_user") ?>" autocomplete="off"></div>
                            </div>
                            <div class="field"><label><?= $t('settings.extdb_pass') ?></label><input type="password" name="extdb_<?= $xk ?>_pass" value="<?= $o("extdb_{$xk}_pass") ?>" autocomplete="new-password"></div>
                        </div>
                    </div>
                <?php }; ?>
                <div class="integration-block">
                    <div style="font-weight:700">CS2-SimpleAdmin</div>
                    <?php $dbBlock('simpleadmin'); ?>
                </div>
                <div class="integration-block">
                    <div class="check"><input type="checkbox" name="vip_enabled" id="vip_enabled" <?= isset($old['vip_enabled']) || !$old ? 'checked' : '' ?>><label for="vip_enabled"><?= $t('install.vip_enable') ?></label></div>
                    <div class="field" style="margin-top:10px">
                        <label><?= $t('install.vip_groups') ?> <span class="hint-inline" title="<?= $t('install.vip_groups_tip') ?>">?</span></label>
                        <textarea name="vip_groups" rows="3" placeholder="VIP&#10;GOLD&#10;PLATINUM"><?= $o('vip_groups') ?></textarea>
                        <p class="hint"><?= $t('install.vip_groups_hint') ?></p>
                    </div>
                    <?php $dbBlock('vipcore'); ?>
                </div>
                <div class="integration-block">
                    <?php $backend = $o('stats_backend', 'zenith'); ?>
                    <div class="check"><input type="checkbox" name="zenith_enabled" id="zenith_enabled" <?= isset($old['zenith_enabled']) || !$old ? 'checked' : '' ?>><label for="zenith_enabled"><?= $t('install.stats_enable') ?></label></div>
                    <div class="zenith-fields" id="zenith-fields">
                        <div class="field" style="margin-top:10px">
                            <label><?= $t('install.stats_plugin') ?> <span class="hint-inline" title="<?= $t('install.stats_plugin_tip') ?>">?</span></label>
                            <label class="inline-check"><input type="radio" name="stats_backend" value="zenith" <?= $backend !== 'k4system' ? 'checked' : '' ?>> K4-Zenith</label>
                            <label class="inline-check"><input type="radio" name="stats_backend" value="k4system" <?= $backend === 'k4system' ? 'checked' : '' ?>> K4-System</label>
                        </div>

                        <!-- K4-Zenith tables -->
                        <div class="backend-fields" id="backend-zenith">
                            <div class="form-row">
                                <div class="field"><label><?= $t('install.ranks_table') ?></label><input type="text" name="zenith_ranks" value="<?= $o('zenith_ranks', 'zenith_ranks') ?>"></div>
                                <div class="field"><label><?= $t('install.stats_table') ?></label><input type="text" name="zenith_stats" value="<?= $o('zenith_stats', 'zenith_stats') ?>"></div>
                            </div>
                            <div class="form-row">
                                <div class="field"><label><?= $t('install.storage_table') ?></label><input type="text" name="zenith_storage" value="<?= $o('zenith_storage', 'zenith_player_storage') ?>"></div>
                                <div class="field"><label><?= $t('install.steam_col') ?> <span class="hint-inline" title="<?= $t('install.steam_col_tip') ?>">?</span></label><input type="text" name="zenith_steam_col" value="<?= $o('zenith_steam_col', 'steam_id') ?>"></div>
                            </div>
                            <?php $dbBlock('zenith'); ?>
                        </div>

                        <!-- K4-System tables -->
                        <div class="backend-fields" id="backend-k4system">
                            <div class="form-row">
                                <div class="field"><label><?= $t('install.table_prefix') ?> <span class="hint-inline" title="<?= $t('install.k4_prefix_tip') ?>">?</span></label><input type="text" name="k4_prefix" value="<?= $o('k4_prefix') ?>" placeholder="<?= $t('install.none') ?>"></div>
                                <div class="field"><label><?= $t('install.steam_col') ?></label><input type="text" name="k4_steam" value="<?= $o('k4_steam', 'steam_id') ?>"></div>
                            </div>
                            <div class="form-row">
                                <div class="field"><label><?= $t('install.ranks_table') ?></label><input type="text" name="k4_ranks_table" value="<?= $o('k4_ranks_table', 'k4ranks') ?>"></div>
                                <div class="field"><label><?= $t('install.stats_table') ?></label><input type="text" name="k4_stats_table" value="<?= $o('k4_stats_table', 'k4stats') ?>"></div>
                            </div>
                            <div class="form-row">
                                <div class="field"><label><?= $t('install.times_table') ?></label><input type="text" name="k4_times_table" value="<?= $o('k4_times_table', 'k4times') ?>"></div>
                                <div class="field"><label><?= $t('install.rank_style') ?> <span class="hint-inline" title="<?= $t('install.rank_style_tip') ?>">?</span></label>
                                    <?php $rt = $o('k4_rank_type', 'competitive'); ?>
                                    <select name="k4_rank_type">
                                        <option value="competitive" <?= $rt !== 'premier' ? 'selected' : '' ?>>Competitive</option>
                                        <option value="premier" <?= $rt === 'premier' ? 'selected' : '' ?>>Premier</option>
                                    </select>
                                </div>
                            </div>
                            <?php $dbBlock('k4system'); ?>
                        </div>
                    </div>
                </div>

                <div class="integration-block">
                    <div class="check"><input type="checkbox" name="skins_enabled" id="skins_enabled" <?= isset($old['skins_enabled']) ? 'checked' : '' ?>><label for="skins_enabled"><?= $t('install.skins_enable') ?></label></div>
                    <p class="hint"><?= $t('install.skins_hint') ?></p>
                    <div class="field" style="margin-top:10px">
                        <label><?= $t('install.skins_prefix') ?> <span class="hint-inline" title="<?= $t('install.skins_prefix_tip') ?>">?</span></label>
                        <input type="text" name="wp_prefix" value="<?= $o('wp_prefix', 'wp_') ?>" placeholder="wp_">
                    </div>
                    <?php $dbBlock('weaponpaints'); ?>
                </div>
                <div class="integration-block">
                    <div style="font-weight:700">Chat Logger</div>
                    <?php $dbBlock('chatlogs'); ?>
                </div>
                <div class="integration-block">
                    <div style="font-weight:700">Store Credits</div>
                    <?php $dbBlock('store'); ?>
                </div>


                <h3 class="section-title">Discord</h3>
                <div class="field">
                    <label><?= $t('install.discord_webhook') ?></label>
                    <input type="url" name="discord_webhook" placeholder="https://discord.com/api/webhooks/..." value="<?= $o('discord_webhook') ?>">
                </div>

                <button type="submit" class="btn btn-primary install-submit"><?= $t('install.submit') ?></button>
                <p class="req-note"><span class="req">*</span> <?= $t('install.required_note') ?></p>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle the stats block visibility based on the enable checkbox, and show the
// field group for whichever stats plugin (Zenith / K4-System) is selected.
(function () {
    var cb = document.getElementById('zenith_enabled');
    var box = document.getElementById('zenith-fields');
    function sync() { if (box) box.style.display = cb.checked ? '' : 'none'; }
    if (cb && box) { cb.addEventListener('change', sync); sync(); }

    var zBox = document.getElementById('backend-zenith');
    var kBox = document.getElementById('backend-k4system');
    function syncBackend() {
        var sel = document.querySelector('input[name="stats_backend"]:checked');
        var v = sel ? sel.value : 'zenith';
        if (zBox) zBox.style.display = v === 'k4system' ? 'none' : '';
        if (kBox) kBox.style.display = v === 'k4system' ? '' : 'none';
    }
    document.querySelectorAll('input[name="stats_backend"]').forEach(function (r) {
        r.addEventListener('change', syncBackend);
    });
    syncBackend();

    // Separate database blocks: show the fields only when ticked; the database
    // name is required only for ticked integrations.
    document.querySelectorAll('.js-extdb-toggle').forEach(function (cbx) {
        var fields = document.getElementById(cbx.getAttribute('data-target'));
        function syncDb() {
            if (!fields) return;
            fields.hidden = !cbx.checked;
            fields.querySelectorAll('[data-extdb-required]').forEach(function (el) {
                if (cbx.checked) { el.setAttribute('required', ''); } else { el.removeAttribute('required'); el.classList.remove('input-error'); }
            });
        }
        cbx.addEventListener('change', syncDb);
        syncDb();
    });

    // Client-side required validation with a clear message.
    var form = document.getElementById('install-form');
    form.addEventListener('submit', function (e) {
        var missing = [];
        form.querySelectorAll('[required]').forEach(function (el) {
            el.classList.remove('input-error');
            if (el.offsetParent === null) { return; }
            if (!el.value.trim()) { missing.push(el); el.classList.add('input-error'); }
        });
        if (missing.length) {
            e.preventDefault();
            missing[0].focus();
            missing[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();
</script>
