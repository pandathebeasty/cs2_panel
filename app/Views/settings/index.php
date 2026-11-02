<?php
use App\Core\Lang;
use App\Core\View;
/** @var array $s @var array $presets @var string $csrf */
$title = Lang::t('nav.settings');
$L = fn(string $k) => View::e(Lang::t($k));
?>
<div class="tab-host" style="max-width:none">
    <?php
    // Tabs, sorted alphabetically by their TRANSLATED label so the order is
    // correct in every language. id => label.
    $tabLabels = [
        'general'      => Lang::t('settings.tab_general'),
        'themes'       => Lang::t('settings.tab_themes'),
        'contests'     => Lang::t('settings.tab_contests'),
        'discord'      => Lang::t('settings.tab_discord'),
        'integrations' => Lang::t('settings.tab_integrations'),
        'store'        => Lang::t('settings.tab_store'),
        'benefits'     => Lang::t('settings.tab_benefits'),
        'admin'        => Lang::t('settings.tab_admin'),
    ];
    if (App\Core\Auth::isRoot()) {
        $tabLabels['updates'] = Lang::t('nav.update');
    }

    // Tabs organized into labeled subgroups instead of one long flat row.
    //  - Panel: how the panel itself looks/behaves (general = branding+theme+steam+session, themes, updates)
    //  - Misc:  feature integrations (contests, discord, integrations, store)
    $groups = [
        'panel' => ['label' => Lang::t('settings.group_panel'), 'tabs' => ['general', 'themes', 'updates']],
        'misc'  => ['label' => Lang::t('settings.group_misc'),  'tabs' => ['contests', 'discord', 'integrations', 'store', 'benefits']],
        'admin' => ['label' => Lang::t('settings.group_admin'), 'tabs' => ['admin']],
    ];

    // First visible tab (first tab of the first group that actually exists).
    $firstTab = null;
    foreach ($groups as $g) {
        foreach ($g['tabs'] as $tid) {
            if (isset($tabLabels[$tid])) { $firstTab = $tid; break 2; }
        }
    }
    ?>
    <div class="settings-tabgroups">
        <?php foreach ($groups as $gid => $g): ?>
            <?php $tabsInGroup = array_values(array_filter($g['tabs'], static fn($t) => isset($tabLabels[$t]))); ?>
            <?php if (!$tabsInGroup) { continue; } ?>
            <div class="settings-tabgroup" data-tabgroup="<?= View::e($gid) ?>">
                <div class="settings-tabgroup-label"><?= View::e($g['label']) ?></div>
                <div class="tabs">
                    <?php foreach ($tabsInGroup as $tabId): ?>
                        <button type="button" data-tab="<?= View::e($tabId) ?>"<?= $tabId === $firstTab ? ' class="active"' : '' ?>><?= View::e($tabLabels[$tabId]) ?><?php if ($tabId === 'updates' && !empty($update['hasUpdate'])): ?> <span class="tab-badge">1</span><?php endif; ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="post" action="/settings" enctype="multipart/form-data" id="settings-form">
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="active_tab" id="settings-active-tab" value="">
        <?php if (($_GET['diag'] ?? '') === '1'): ?><input type="hidden" name="diag" value="1"><?php endif; ?>
        <!-- Theme selection is committed synchronously with the main Save (filled by JS). -->
        <input type="hidden" name="theme_name" id="settings-theme-name" value="">
        <input type="hidden" name="theme_mode" id="settings-theme-mode" value="">
        <input type="hidden" name="custom_themes" id="settings-custom-themes" value="">

        <!-- General (branding + appearance) -->
        <div data-tabpanel="general">
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.branding2') ?></div>
                <div class="form-row">
                    <div class="field"><label><?= $L('common.name') ?></label><input type="text" name="site_name" value="<?= View::e($s['site_name']) ?>"></div>
                    <div class="field"><label><?= $L('settings.url') ?></label><input type="url" name="website_url" value="<?= View::e($s['website_url']) ?>" placeholder="https://panel.example.com"></div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label><?= $L('settings.logo') ?></label>
                        <div class="file-drop">
                            <span class="file-btn"><?= $L('settings.choose_file') ?></span>
                            <span class="file-name" data-placeholder="<?= $L('settings.no_file') ?>"><?= $L('settings.no_file') ?></span>
                            <input type="file" name="logo" accept="image/*">
                        </div>
                        <?php if ($s['logo_path']): ?>
                            <div style="margin-top:8px;display:flex;align-items:center;gap:10px">
                                <img src="<?= View::e($s['logo_path']) ?>" alt="logo" style="height:44px;border-radius:8px;background:var(--bg-3);padding:4px">
                                <label class="reset-toggle"><input type="checkbox" name="reset_logo" value="1"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v6h6"/><path d="M3.5 8a9 9 0 1 0 2.1-3.4L3 8"/></svg><?= $L('settings.reset_default') ?></span></label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label><?= $L('settings.favicon') ?></label>
                        <div class="file-drop">
                            <span class="file-btn"><?= $L('settings.choose_file') ?></span>
                            <span class="file-name" data-placeholder="<?= $L('settings.no_file') ?>"><?= $L('settings.no_file') ?></span>
                            <input type="file" name="favicon" accept="image/*,.ico">
                        </div>
                        <?php if ($s['favicon_path']): ?>
                            <div style="margin-top:8px;display:flex;align-items:center;gap:10px">
                                <img src="<?= View::e($s['favicon_path']) ?>" alt="favicon" style="height:30px;border-radius:6px;background:var(--bg-3);padding:4px">
                                <label class="reset-toggle"><input type="checkbox" name="reset_favicon" value="1"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v6h6"/><path d="M3.5 8a9 9 0 1 0 2.1-3.4L3 8"/></svg><?= $L('settings.reset_default') ?></span></label>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field" style="max-width:220px"><label><?= $L('settings.per_page') ?></label><input type="number" name="per_page" value="<?= View::e($s['per_page']) ?>"></div>
                    <div class="field">
                        <label><?= $L('settings.timezone') ?></label>
                        <select name="timezone">
                            <?php foreach ($s['timezones'] as $tz): ?>
                                <option value="<?= View::e($tz) ?>" <?= $s['timezone'] === $tz ? 'selected' : '' ?>><?= View::e($tz) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint" style="margin-top:6px"><?= $L('settings.timezone_hint') ?></p>
                    </div>
                    <?php if (App\Core\Auth::isRoot()): ?>
                    <div class="field" style="max-width:220px">
                        <label><?= $L('settings.language') ?></label>
                        <select name="site_language">
                            <?php foreach (\App\Core\Lang::AVAILABLE as $lc => $meta): ?>
                                <option value="<?= View::e($lc) ?>" <?= ($s['site_language'] ?? 'en') === $lc ? 'selected' : '' ?>><?= View::e($meta['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint" style="margin-top:6px"><?= $L('settings.language_hint') ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Maintenance cron (general): runs contests, admin-perk expiry and
                 stale-order cancellation. Moved here from the Contests tab. -->
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.cron') ?></div>
                <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.cron_hint') ?></p>
                <?php
                    $cronBase = rtrim((string) $s['website_url'], '/');
                    if ($cronBase === '') { $cronBase = 'https://YOUR-SITE'; }
                    $cronUrl  = $cronBase . '/cron?token=' . rawurlencode((string) $s['cron_token']);
                    $cronCurl = 'curl -s "' . $cronUrl . '" >/dev/null 2>&1';
                    $cronFull = '* * * * * ' . $cronCurl;
                ?>

                <!-- Command-only (for panels with separate time fields) -->
                <div class="field" style="margin-bottom:14px">
                    <label><?= $L('settings.cron_cmd') ?></label>
                    <div class="code-copy">
                        <code class="code-line" id="cron-cmd"><?= View::e($cronCurl) ?></code>
                        <button type="button" class="btn btn-sm js-copy" data-copy-target="#cron-cmd"><?= $L('common.copy') ?></button>
                    </div>
                    <p class="hint" style="margin-top:6px"><?= $L('settings.cron_cmd_hint') ?></p>
                </div>

                <!-- Full crontab line (for a single crontab entry) -->
                <div class="field" style="margin-bottom:14px">
                    <label><?= $L('settings.cron_full') ?></label>
                    <div class="code-copy">
                        <code class="code-line" id="cron-full"><?= View::e($cronFull) ?></code>
                        <button type="button" class="btn btn-sm js-copy" data-copy-target="#cron-full"><?= $L('common.copy') ?></button>
                    </div>
                    <p class="hint" style="margin-top:6px"><?= $L('settings.cron_note') ?></p>
                </div>

                <div class="field">
                    <label><?= $L('settings.cron_token_label') ?></label>
                    <div class="code-copy">
                        <code class="code-line code-secret"><?= View::e((string) $s['cron_token']) ?></code>
                        <button type="submit" name="regenerate_cron_token" value="1" class="btn btn-sm btn-danger"
                            data-confirm="<?= $L('settings.cron_regen_confirm') ?>"><?= $L('settings.cron_regen') ?></button>
                    </div>
                    <p class="hint" style="margin-top:6px"><?= $L('settings.cron_token_hint') ?></p>
                </div>
            </div>
        </div>

        <!-- Themes -->
        <div data-tabpanel="themes" style="display:none">
            <?php
            $sortedPresets = $presets;
            uasort($sortedPresets, static fn(array $a, array $b): int => strcasecmp((string) $a['label'], (string) $b['label']));
            // Split discovered themes: truly animated vs flat/static (e.g.
            // SourceBans). Static ones render in the preset grid, not Animated.
            $discovered = \App\Core\Themes::all();
            $staticTags = ['classic', 'flat', 'static'];
            $animated = [];
            $staticThemes = [];
            foreach ($discovered as $dt) {
                if (in_array(strtolower((string) $dt['tag']), $staticTags, true)) {
                    $staticThemes[] = $dt;
                } else {
                    $animated[] = $dt;
                }
            }
            ?>
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.preset_themes') ?></div>
                <p class="hint" style="margin:-6px 0 16px"><?= $L('settings.preset_hint') ?></p>
                <div class="theme-grid">
                    <?php foreach ($sortedPresets as $id => $p): ?>
                        <button type="button" class="theme-card" data-theme-set="<?= View::e($id) ?>" style="--tp-accent:<?= View::e($p['accent']) ?>;">
                            <span class="theme-check">&check;</span>
                            <span class="theme-preview split">
                                <span class="tp-half tp-dark"><span class="tp-logo"></span><span class="tp-line on"></span><span class="tp-line"></span></span>
                                <span class="tp-half tp-light"><span class="tp-logo"></span><span class="tp-line on"></span><span class="tp-line"></span></span>
                            </span>
                            <span class="theme-meta">
                                <span class="theme-name"><?= View::e($p['label']) ?></span>
                                <span class="theme-tag"><?= View::e($p['tag']) ?></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                    <?php foreach ($staticThemes as $t): ?>
                        <button type="button" class="theme-card" data-theme-set="<?= View::e($t['id']) ?>" style="--tp-accent:<?= View::e($t['accent']) ?>;">
                            <span class="theme-check">&check;</span>
                            <span class="theme-preview split">
                                <span class="tp-half tp-dark"><span class="tp-logo"></span><span class="tp-line on"></span><span class="tp-line"></span></span>
                                <span class="tp-half tp-light"><span class="tp-logo"></span><span class="tp-line on"></span><span class="tp-line"></span></span>
                            </span>
                            <span class="theme-meta">
                                <span class="theme-name"><?= View::e($t['name']) ?></span>
                                <span class="theme-tag"><?= View::e($t['tag']) ?></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Animated themes (auto-discovered from assets/css/themes/*.css) -->
            <?php if ($animated): ?>
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.animated_themes') ?></div>
                <p class="hint" style="margin:-6px 0 16px"><?= $L('settings.animated_hint') ?></p>
                <div class="theme-grid">
                    <?php foreach ($animated as $t): ?>
                        <?php /* Each card shows a distinct per-theme mini preview
                                 keyed by the theme id (data-preview). A self-contained
                                 CSS pattern per theme — NOT the live .theme-fx, which
                                 leaks the ACTIVE theme into every card (the effect
                                 selectors match any .theme-fx under <html data-theme>). */ ?>
                        <button type="button" class="theme-card theme-card-anim" data-theme-set="<?= View::e($t['id']) ?>"
                                data-preview="<?= View::e($t['id']) ?>"
                                style="--tp-accent:<?= View::e($t['accent']) ?>;">
                            <span class="theme-check">&check;</span>
                            <span class="theme-preview anim">
                                <span class="tp-anim-glow"></span>
                                <span class="tp-pattern" aria-hidden="true"></span>
                                <span class="tp-line on"></span><span class="tp-line"></span>
                            </span>
                            <span class="theme-meta">
                                <span class="theme-name"><?= View::e($t['name']) ?></span>
                                <span class="theme-tag"><?= View::e($t['tag']) ?></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Accent color only for the Default theme -->
            <div class="card" style="margin-bottom:18px" id="accent-card">
                <div class="accent-row" data-accent-sync>
                    <label><?= $L('settings.accent') ?></label>
                    <input type="color" value="<?= View::e($s['theme_accent']) ?>">
                    <input type="hidden" name="theme_accent" value="<?= View::e($s['theme_accent']) ?>">
                    <button type="button" class="btn btn-sm" id="accent-reset"><?= $L('common.reset') ?></button>
                </div>
            </div>

            <!-- Custom themes: full palette for both light & dark -->
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.custom_themes') ?></div>
                <div class="theme-grid" id="custom-theme-grid" style="margin-bottom:16px"></div>

                <div class="field" style="max-width:340px"><label><?= $L('settings.theme_name') ?></label><input type="text" id="ct-name" placeholder="e.g. Ocean Breeze"></div>

                <div class="ct-modes">
                    <div class="ct-mode">
                        <div class="ct-mode-title"><?= $L('settings.dark_variant') ?></div>
                        <div class="ct-swatches">
                            <label class="ct-sw"><input type="color" id="ctd-bg" value="#0b0c11"><span><?= $L('settings.sw_bg') ?></span></label>
                            <label class="ct-sw"><input type="color" id="ctd-card" value="#15161f"><span><?= $L('settings.sw_surface') ?></span></label>
                            <label class="ct-sw"><input type="color" id="ctd-border" value="#262838"><span><?= $L('settings.sw_border') ?></span></label>
                            <label class="ct-sw"><input type="color" id="ctd-text" value="#e8eaf2"><span><?= $L('settings.sw_text') ?></span></label>
                            <label class="ct-sw"><input type="color" id="ctd-mut" value="#6b6e82"><span><?= $L('settings.sw_muted') ?></span></label>
                        </div>
                    </div>
                    <div class="ct-mode">
                        <div class="ct-mode-title"><?= $L('settings.light_variant') ?></div>
                        <div class="ct-swatches">
                            <label class="ct-sw"><input type="color" id="ctl-bg" value="#ccd3d9"><span><?= $L('settings.sw_bg') ?></span></label>
                            <label class="ct-sw"><input type="color" id="ctl-card" value="#d5dde3"><span><?= $L('settings.sw_surface') ?></span></label>
                            <label class="ct-sw"><input type="color" id="ctl-border" value="#929fa9"><span><?= $L('settings.sw_border') ?></span></label>
                            <label class="ct-sw"><input type="color" id="ctl-text" value="#1e2b35"><span><?= $L('settings.sw_text') ?></span></label>
                            <label class="ct-sw"><input type="color" id="ctl-mut" value="#425360"><span><?= $L('settings.sw_muted') ?></span></label>
                        </div>
                    </div>
                </div>

                <div class="ct-row" style="margin-top:14px">
                    <label class="ct-accent-label"><?= $L('settings.accent') ?></label>
                    <input type="color" id="ct-accent" value="#3fb6ff" title="Accent">
                    <button type="button" class="btn btn-primary" id="ct-save"><?= $L('common.save') ?></button>
                </div>
            </div>
        </div>

        <!-- Integrations -->
        <div data-tabpanel="integrations" style="display:none">
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.integrations2') ?></div>
                <div class="check" style="margin-bottom:10px"><input type="checkbox" name="vip_enabled" id="vip_en" <?= $s['vip_enabled'] ? 'checked' : '' ?>><label for="vip_en"><?= $L('settings.enable_vip') ?></label></div>
                <p class="hint" style="margin:-4px 0 12px"><?= $L('settings.vip_hint') ?></p>
                <div class="field" style="margin-bottom:16px">
                    <label><?= $L('settings.vip_groups') ?></label>
                    <textarea name="vip_groups" rows="4" placeholder="VIP&#10;GOLD&#10;PLATINUM"><?= View::e($s['vip_groups']) ?></textarea>
                    <p class="hint" style="margin-top:6px"><?= $L('settings.vip_groups_hint') ?></p>
                </div>
                <div class="field" style="margin-bottom:16px">
                    <label><?= $L('settings.ignored_vip') ?></label>
                    <input type="text" name="vip_ignored_groups" value="<?= View::e($s['vip_ignored_groups']) ?>" placeholder="e.g. GOLD, admin_vip, internal">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.ignored_vip_hint') ?></p>
                </div>
                <div class="field" style="margin-bottom:16px">
                    <label><?= $L('settings.vip_time_mode') ?></label>
                    <select name="vip_time_mode">
                        <?php foreach (['0' => '0 - seconds', '1' => '1 - minutes', '2' => '2 - hours', '3' => '3 - days'] as $mv => $ml): ?>
                            <option value="<?= $mv ?>"<?= (string) ($s['vip_time_mode'] ?? '3') === (string) $mv ? ' selected' : '' ?>><?= View::e($ml) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint" style="margin-top:6px"><?= $L('settings.vip_time_mode_hint') ?></p>
                </div>

                <?php $k4Backend = ($s['stats_backend'] ?? 'zenith') === 'k4system'; ?>
                <div class="field" style="margin-bottom:12px">
                    <label><?= $L('settings.stats_plugin') ?></label>
                    <label class="inline-check"><input type="radio" name="stats_backend" value="zenith" <?= !$k4Backend ? 'checked' : '' ?>> <?= $L('settings.backend_zenith') ?></label>
                    <label class="inline-check"><input type="radio" name="stats_backend" value="k4system" <?= $k4Backend ? 'checked' : '' ?>> <?= $L('settings.backend_k4system') ?></label>
                    <p class="hint"><?= $L('settings.stats_plugin_hint') ?></p>
                </div>

                <!-- K4-Zenith table mapping -->
                <div class="backend-fields" id="backend-zenith">
                    <div class="form-row">
                        <div class="field"><label><?= $L('settings.ranks_table') ?></label><input type="text" name="zenith_ranks" value="<?= View::e($s['zenith_ranks']) ?>"></div>
                        <div class="field"><label><?= $L('settings.stats_table') ?></label><input type="text" name="zenith_stats" value="<?= View::e($s['zenith_stats']) ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="field"><label><?= $L('settings.storage_table') ?></label><input type="text" name="zenith_storage" value="<?= View::e($s['zenith_storage']) ?>"></div>
                        <div class="field"><label><?= $L('settings.steam_column') ?></label><input type="text" name="zenith_steam" value="<?= View::e($s['zenith_steam']) ?>"></div>
                    </div>
                </div>

                <!-- K4-System table mapping -->
                <div class="backend-fields" id="backend-k4system">
                    <div class="form-row">
                        <div class="field"><label><?= $L('settings.k4_prefix') ?></label><input type="text" name="k4_prefix" value="<?= View::e($s['k4_prefix']) ?>" placeholder="(none)"></div>
                        <div class="field"><label><?= $L('settings.steam_column') ?></label><input type="text" name="k4_steam" value="<?= View::e($s['k4_steam']) ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="field"><label><?= $L('settings.ranks_table') ?></label><input type="text" name="k4_ranks_table" value="<?= View::e($s['k4_ranks_table']) ?>"></div>
                        <div class="field"><label><?= $L('settings.stats_table') ?></label><input type="text" name="k4_stats_table" value="<?= View::e($s['k4_stats_table']) ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="field"><label><?= $L('settings.k4_times_table') ?></label><input type="text" name="k4_times_table" value="<?= View::e($s['k4_times_table']) ?>"></div>
                        <div class="field"><label><?= $L('settings.rank_style') ?></label>
                            <select name="k4_rank_type">
                                <option value="competitive" <?= ($s['k4_rank_type'] ?? 'competitive') !== 'premier' ? 'selected' : '' ?>><?= $L('settings.rank_competitive') ?></option>
                                <option value="premier" <?= ($s['k4_rank_type'] ?? '') === 'premier' ? 'selected' : '' ?>><?= $L('settings.rank_premier') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    var zBox = document.getElementById('backend-zenith');
                    var kBox = document.getElementById('backend-k4system');
                    function sync() {
                        var sel = document.querySelector('input[name="stats_backend"]:checked');
                        var v = sel ? sel.value : 'zenith';
                        if (zBox) zBox.style.display = v === 'k4system' ? 'none' : '';
                        if (kBox) kBox.style.display = v === 'k4system' ? '' : 'none';
                    }
                    document.querySelectorAll('input[name="stats_backend"]').forEach(function (r) { r.addEventListener('change', sync); });
                    sync();
                })();
                </script>
            </div>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.skins_title') ?></div>
                <div class="check" style="margin-bottom:14px"><input type="checkbox" name="skins_enabled" id="skins_en" <?= $s['skins_enabled'] ? 'checked' : '' ?>><label for="skins_en"><?= $L('settings.enable_skins') ?></label></div>
                <div class="field">
                    <label><?= $L('settings.wp_prefix') ?></label>
                    <input type="text" name="wp_prefix" value="<?= View::e($s['wp_prefix']) ?>" placeholder="wp_">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.wp_prefix_hint') ?></p>
                </div>
                <div class="field" style="margin-top:14px">
                    <label><?= $L('settings.skins_rarity_title') ?></label>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <button type="button" class="btn btn-primary btn-sm" id="skins-rarity-check"><?= $L('settings.skins_rarity_check') ?></button>
                        <span class="hint" id="skins-rarity-result" style="margin:0"></span>
                    </div>
                    <p class="hint" style="margin-top:6px"><?= $L('settings.skins_rarity_hint') ?></p>
                </div>
            </div>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.chat_logs_title') ?></div>
                <div class="check" style="margin-bottom:14px"><input type="checkbox" name="chat_logs_enabled" id="chat_logs_en" <?= $s['chat_logs_enabled'] ? 'checked' : '' ?>><label for="chat_logs_en"><?= $L('settings.enable_chat_logs') ?></label></div>
                <p class="hint"><?= $L('settings.chat_logs_hint') ?></p>
                <div class="field">
                    <label for="chat_logs_table"><?= $L('settings.chat_logs_table') ?></label>
                    <input type="text" id="chat_logs_table" name="chat_logs_table" maxlength="64" pattern="[A-Za-z0-9_]{1,64}" required value="<?= View::e($s['chat_logs_table']) ?>" placeholder="chat_logs">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.chat_logs_table_hint') ?></p>
                </div>
            </div>

            <!-- Skins update progress popup (same look as the site-update HUD). -->
            <div class="update-hud" id="skins-hud" aria-hidden="true">
                <div class="update-hud-box">
                    <div class="update-hud-title"><?= $L('settings.skins_rarity_title') ?></div>
                    <div class="update-hud-percent" id="skins-hud-percent">0%</div>
                    <div class="update-hud-track"><div class="update-hud-bar" id="skins-hud-bar"></div></div>
                    <div class="update-hud-step" id="skins-hud-step"><?= $L('settings.skins_rarity_checking') ?></div>
                    <div class="update-hud-log" id="skins-hud-log"></div>
                    <div class="update-hud-hint"><?= $L('settings.skins_rarity_hint') ?></div>
                    <div style="margin-top:14px;text-align:right">
                        <button type="button" class="btn btn-sm" id="skins-hud-close" style="display:none"><?= $L('common.close') ?></button>
                    </div>
                </div>
            </div>
            <script>
            (function () {
                var btn = document.getElementById('skins-rarity-check');
                var out = document.getElementById('skins-rarity-result');
                if (!btn) return;
                var hud = document.getElementById('skins-hud');
                // position:fixed is relative to the nearest transformed/filtered
                // ancestor, not the viewport. The HUD sits inside the settings
                // tab content, so move it to <body> to guarantee it centres on
                // the whole screen (like the site-update HUD).
                if (hud && hud.parentNode !== document.body) { document.body.appendChild(hud); }
                var hudBar = document.getElementById('skins-hud-bar');
                var hudPct = document.getElementById('skins-hud-percent');
                var hudStep = document.getElementById('skins-hud-step');
                var hudLog = document.getElementById('skins-hud-log');
                var hudClose = document.getElementById('skins-hud-close');
                var busy = <?= json_encode(Lang::t('settings.skins_rarity_checking')) ?>;
                var okTpl = <?= json_encode(Lang::t('settings.skins_rarity_ok')) ?>;
                var failTpl = <?= json_encode(Lang::t('settings.skins_rarity_failed')) ?>;
                var doneTxt = <?= json_encode(Lang::t('settings.skins_rarity_done')) ?>;
                var stepItems = <?= json_encode(Lang::t('settings.skins_rarity_step_items')) ?>;
                var stepColours = <?= json_encode(Lang::t('settings.skins_rarity_step_colours')) ?>;

                function logLine(txt) { var d = document.createElement('div'); d.textContent = txt; hudLog.appendChild(d); }
                function setPct(p) { p = Math.max(0, Math.min(100, p)); hudBar.style.width = p + '%'; hudPct.textContent = Math.round(p) + '%'; }

                // The refresh is a single server call, so we can't stream real
                // sub-steps; instead run a smooth faux-progress toward 90% while
                // the request is in flight, then jump to 100% on the response.
                var timer = null;
                function startFauxProgress() {
                    var p = 0; setPct(0);
                    timer = setInterval(function () {
                        p += Math.max(0.5, (90 - p) * 0.06);
                        if (p >= 90) { p = 90; clearInterval(timer); timer = null; }
                        setPct(p);
                    }, 180);
                }
                function stopFauxProgress() { if (timer) { clearInterval(timer); timer = null; } }

                function openHud() {
                    hudLog.innerHTML = '';
                    hudStep.classList.remove('is-error');
                    hudStep.textContent = busy;
                    hudClose.style.display = 'none';
                    hud.classList.add('open');
                    hud.setAttribute('aria-hidden', 'false');
                    document.body.style.overflow = 'hidden'; // lock page scroll
                    logLine(stepItems);
                    logLine(stepColours);
                    startFauxProgress();
                }
                function closeHud() {
                    hud.classList.remove('open');
                    hud.setAttribute('aria-hidden', 'true');
                    document.body.style.overflow = ''; // restore page scroll
                }
                hudClose.addEventListener('click', closeHud);
                // Allow Escape to dismiss, but only once the run finished (the
                // close button is visible) — not mid-download.
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && hud.classList.contains('open') && hudClose.style.display !== 'none') {
                        closeHud();
                    }
                });

                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    out.textContent = busy;
                    openHud();
                    var body = new URLSearchParams({ csrf: window.CS2_CSRF || '' }).toString();
                    var closeLabel = hudClose.textContent;
                    function fail(reason) {
                        stopFauxProgress(); setPct(100);
                        var err = failTpl.replace('{e}', reason);
                        out.textContent = err;
                        hudStep.classList.add('is-error');
                        hudStep.textContent = err;
                        logLine(err);
                        hudClose.textContent = closeLabel; // "Close" on error
                    }
                    fetch((window.CS2_BASE || '') + '/settings/skins-rarity-refresh', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        body: body
                    }).then(function (r) {
                        // Read as text first so a non-JSON error page (419/500/
                        // timeout) surfaces the real status instead of a blanket
                        // "network" from a thrown r.json().
                        return r.text().then(function (t) {
                            var j = null; try { j = JSON.parse(t); } catch (e) {}
                            if (!j) {
                                // Non-JSON (an HTML error page). Don't dump raw
                                // markup — drop <style>/<script> bodies, strip
                                // tags and collapse whitespace to a short snippet.
                                var plain = (t || '')
                                    .replace(/<(style|script)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ')
                                    .replace(/<[^>]*>/g, ' ')
                                    .replace(/\s+/g, ' ').trim();
                                fail('HTTP ' + r.status + (plain ? ' — ' + plain.slice(0, 300) : ''));
                                return;
                            }
                            stopFauxProgress(); setPct(100);
                            if (j.ok) {
                                var msg = okTpl.replace('{n}', j.count).replace('{r}', j.rarity);
                                out.textContent = msg;
                                hudStep.textContent = doneTxt;
                                logLine(msg);
                                hudClose.textContent = doneTxt.replace(/\.$/, ''); // "Done" on success
                            } else {
                                fail(j.error ? j.error : '?');
                            }
                        });
                    }).catch(function () {
                        fail('network');
                    }).finally(function () {
                        btn.disabled = false;
                        hudClose.style.display = '';
                    });
                });
            })();
            </script>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.community') ?></div>
                <div class="check" style="margin-bottom:10px"><input type="checkbox" name="appeals_enabled" id="appeals_en" <?= $s['appeals_enabled'] ? 'checked' : '' ?>><label for="appeals_en"><?= $L('settings.enable_appeals') ?></label></div>
                <p class="hint" style="margin:-4px 0 12px"><?= $L('settings.appeals_hint') ?></p>
                <div class="check" style="margin-bottom:10px"><input type="checkbox" name="reports_enabled" id="reports_en" <?= $s['reports_enabled'] ? 'checked' : '' ?>><label for="reports_en"><?= $L('settings.enable_reports') ?></label></div>
                <p class="hint" style="margin:-4px 0 0"><?= $L('settings.reports_hint') ?></p>
            </div>

            <!-- Store credits (schwarper/cs2-store) — an integration, not the
                 PayPal store. Enable + table name live here; the credit PACKAGES
                 are defined in the Store tab, next to VIP/admin packages. -->
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.store_credits') ?></div>
                <div class="check" style="margin-bottom:10px"><input type="checkbox" name="store_credits_enabled" id="store_credits_en" <?= $s['store_credits_enabled'] ? 'checked' : '' ?>><label for="store_credits_en"><?= $L('settings.store_credits_enable') ?></label></div>
                <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.store_credits_hint') ?></p>
                <div class="field" style="max-width:360px">
                    <label><?= $L('settings.store_players_table') ?></label>
                    <input type="text" name="store_players_table" value="<?= View::e($s['store_players_table']) ?>" placeholder="store_players">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.store_players_table_hint') ?></p>
                </div>
            </div>

        </div>

        <div data-tabpanel="admin" style="display:none">
            <!-- In-game admin groups (sa_groups) -->
            <?php
            $agFlagGroups = $s['flag_groups'] ?? [];
            $agCustom     = $s['custom_flags'] ?? [];
            $agKnown      = [];
            foreach ($agFlagGroups as $_fl) { foreach ($_fl as $_f => $_n) { $agKnown[$_f] = $_n; } }
            $agFlagBoxes = function (string $inputName, array $checked) use ($agFlagGroups, $agCustom, $agKnown): string {
                // Keep unknown stored flags editable alongside custom flags.
                                                                           
                $all = $agCustom;
                foreach ($checked as $f) { if (!isset($agKnown[$f]) && !in_array($f, $all, true)) { $all[] = $f; } }

                $box = static function (string $inputName, string $flag, string $label, array $checked): string {
                    $id = 'ag_' . substr(md5($inputName . $flag), 0, 10);
                    return '<div class="check"><input type="checkbox" name="' . View::e($inputName) . '" value="' . View::e($flag) . '" id="' . $id . '"'
                        . (in_array($flag, $checked, true) ? ' checked' : '') . '><label for="' . $id . '">' . View::e($label) . '</label></div>';
                };

                $h = '';
                // One titled section per flag group (Admin Flags / K4-Zenith / K4-System).
                foreach ($agFlagGroups as $_label => $flags) {
                    if (!$flags) { continue; }
                    $h .= '<div class="flag-group"><div class="flag-group-title">' . View::e((string) $_label) . '</div><div class="form-row admin-group-flags">';
                    foreach ($flags as $flag => $name) {
                        $h .= $box($inputName, (string) $flag, \App\Core\Flags::label((string) $flag, (string) $name), $checked);
                    }
                    $h .= '</div></div>';
                }
                // Custom / unknown flags in their own section.
                $customValid = array_values(array_filter($all, static fn($f) => \App\Core\Flags::isValid((string) $f)));
                if ($customValid) {
                    $h .= '<div class="flag-group"><div class="flag-group-title">' . View::e(\App\Core\Lang::t('settings.flags_custom')) . '</div><div class="form-row admin-group-flags">';
                    foreach ($customValid as $flag) {
                        $h .= $box($inputName, (string) $flag, (string) $flag, $checked);
                    }
                    $h .= '</div></div>';
                }
                return $h;
            };
            ?>
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.admin_groups') ?></div>
                <div class="field" style="margin-bottom:16px">
                    <label><?= $L('settings.admin_ignored_groups') ?></label>
                    <input type="text" name="admin_ignored_groups" value="<?= View::e($s['admin_ignored_groups']) ?>" placeholder="#vip/gold, #vip/platinum">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.admin_ignored_groups_hint') ?></p>
                </div>

                <?php foreach (($s['admin_groups'] ?? []) as $i => $ag): ?>
                    <?php $visibleGroupFlags = array_values(array_filter($ag['flags'], static fn($f) => \App\Core\Flags::isValid((string) $f))); ?>
                    <details class="card admin-group-details">
                        <summary class="admin-group-summary">
                            <span class="admin-group-title"><?= View::e($ag['label']) ?></span>
                            <span class="muted"><?= count($visibleGroupFlags) ?> <?= $L('groups.flags') ?> · <?= (int) $ag['admin_count'] ?> <?= $L('groups.admins') ?></span>
                        </summary>
                        <div class="admin-group-content">
                        <input type="hidden" name="grp_id[<?= $i ?>]" value="<?= (int) $ag['id'] ?>">
                        <input type="hidden" name="grp_original_name[<?= $i ?>]" value="<?= View::e($ag['name']) ?>">
                        <input type="hidden" name="grp_original_immunity[<?= $i ?>]" value="<?= (int) $ag['immunity'] ?>">
                        <input type="hidden" name="grp_original_flags[<?= $i ?>]" value="<?= View::e(json_encode($visibleGroupFlags, JSON_UNESCAPED_UNICODE) ?: '[]') ?>">
                        <div class="form-row">
                            <div class="field"><label><?= $L('groups.group_name') ?></label><input type="text" name="grp_name[<?= $i ?>]" value="<?= View::e($ag['name']) ?>"></div>
                            <div class="field"><label><?= $L('groups.immunity') ?></label><input type="number" name="grp_immunity[<?= $i ?>]" value="<?= (int) $ag['immunity'] ?>"></div>
                        </div>
                        <?= $agFlagBoxes('grp_flags[' . $i . '][]', $visibleGroupFlags) ?>
                        <button type="button" class="btn btn-sm btn-danger js-admin-group-delete"
                            data-group-id="<?= (int) $ag['id'] ?>"
                            data-confirm="<?= $L('settings.admin_group_delete_confirm') ?>"
                            data-error="<?= $L('settings.admin_group_delete_failed') ?>"><?= $L('common.delete') ?></button>
                        </div>
                    </details>
                <?php endforeach; ?>

                <div class="new-admin-group-launcher">
                    <button type="button" class="btn" id="new-admin-group-open">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        <?= $L('groups.new_title') ?>
                    </button>
                </div>
                <dialog class="modal new-admin-group-dialog" id="new-admin-group-dialog" aria-labelledby="new-admin-group-title">
                    <div class="modal-head">
                        <h3 id="new-admin-group-title"><?= $L('groups.new_title') ?></h3>
                        <button type="button" class="modal-close" data-close-new-admin-group aria-label="Close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-row">
                            <div class="field"><label><?= $L('groups.group_name') ?></label><input type="text" name="grp_new_name" placeholder="#css/admin"></div>
                            <div class="field"><label><?= $L('groups.immunity') ?></label><input type="number" name="grp_new_immunity" value="0"></div>
                        </div>
                        <?= $agFlagBoxes('grp_new_flags[]', []) ?>
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="btn" data-close-new-admin-group><?= $L('common.cancel') ?></button>
                        <button type="submit" class="btn btn-primary" form="settings-form"><?= $L('common.save') ?></button>
                    </div>
                </dialog>
            </div>
        </div>

        <script>
        document.querySelectorAll('.js-admin-group-delete').forEach(function (button) {
            button.addEventListener('click', function () {
                if (!window.confirm(button.dataset.confirm)) return;
                var details = button.closest('details');
                var csrf = document.querySelector('#settings-form input[name="csrf"]');
                var body = new URLSearchParams({ csrf: csrf ? csrf.value : '' });
                button.disabled = true;
                fetch((window.CS2_BASE || '') + '/groups/' + button.dataset.groupId + '/delete', {
                    method: 'POST',
                    body: body,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' }
                }).then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || !data || !data.ok) throw new Error((data && data.error) || button.dataset.error);
                        details.remove();
                    });
                }).catch(function () {
                    button.disabled = false;
                    window.alert(button.dataset.error);
                });
            });
        });
        var newAdminGroupDialog = document.getElementById('new-admin-group-dialog');
        var newAdminGroupOpen = document.getElementById('new-admin-group-open');
        if (newAdminGroupDialog && newAdminGroupOpen) {
            newAdminGroupOpen.addEventListener('click', function () { newAdminGroupDialog.showModal(); });
            newAdminGroupDialog.querySelectorAll('[data-close-new-admin-group]').forEach(function (button) {
                button.addEventListener('click', function () { newAdminGroupDialog.close(); });
            });
            newAdminGroupDialog.addEventListener('click', function (event) {
                if (event.target === newAdminGroupDialog) newAdminGroupDialog.close();
            });
        }
        </script>

                         
        <div data-tabpanel="contests" style="display:none">
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.tab_contests') ?></div>
                <div class="check" style="margin-bottom:10px"><input type="checkbox" name="contests_enabled" id="contests_en" <?= $s['contests_enabled'] ? 'checked' : '' ?>><label for="contests_en"><?= $L('settings.enable_contests') ?></label></div>
                <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.contests_hint') ?></p>
                <div class="field" style="margin-bottom:16px">
                    <label><?= $L('settings.contest_webhook') ?></label>
                    <input type="text" name="contest_webhook" value="<?= View::e($s['contest_webhook']) ?>" placeholder="https://discord.com/api/webhooks/...">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.contest_webhook_hint') ?></p>
                </div>
                <div class="field">
                    <label><?= $L('settings.contest_retention') ?></label>
                    <input type="number" name="contest_retention_days" value="<?= View::e($s['contest_retention_days']) ?>" min="0" max="3650">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.contest_retention_hint') ?></p>
                </div>
            </div>
        </div>

        <!-- Store (PayPal shop) -->
        <div data-tabpanel="store" style="display:none">
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.tab_store') ?></div>
                <div class="check" style="margin-bottom:10px"><input type="checkbox" name="store_enabled" id="store_en" <?= $s['store_enabled'] ? 'checked' : '' ?>><label for="store_en"><?= $L('settings.enable_store') ?></label></div>
                <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.store_hint') ?></p>
                <div class="form-row">
                    <div class="field">
                        <label><?= $L('settings.store_currency') ?></label>
                        <input type="text" name="store_currency" value="<?= View::e($s['store_currency']) ?>" maxlength="8" placeholder="EUR">
                        <p class="hint" style="margin-top:6px"><?= $L('settings.store_currency_hint') ?></p>
                    </div>
                    <div class="field">
                        <label><?= $L('settings.store_paypal_mode') ?></label>
                        <select name="store_paypal_mode">
                            <option value="sandbox" <?= $s['store_paypal_mode'] === 'sandbox' ? 'selected' : '' ?>><?= $L('settings.store_mode_sandbox') ?></option>
                            <option value="live" <?= $s['store_paypal_mode'] === 'live' ? 'selected' : '' ?>><?= $L('settings.store_mode_live') ?></option>
                        </select>
                        <p class="hint" style="margin-top:6px"><?= $L('settings.store_paypal_mode_hint') ?></p>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.store_paypal') ?></div>
                <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.store_paypal_hint') ?></p>

                <!-- Sandbox credentials -->
                <div class="pkg-section-title"><?= $L('settings.store_mode_sandbox') ?><?php if ($s['store_paypal_mode'] === 'sandbox'): ?> · <?= $L('settings.store_env_active') ?><?php endif; ?></div>
                <div class="field" style="margin-bottom:14px">
                    <label><?= $L('settings.store_paypal_client_id') ?></label>
                    <input type="text" name="store_paypal_sandbox_client_id" value="<?= View::e($s['store_paypal_sandbox_client_id']) ?>" autocomplete="off">
                </div>
                <div class="field" style="margin-bottom:14px">
                    <label><?= $L('settings.store_paypal_secret') ?><?php if ($s['store_paypal_sandbox_secret'] !== ''): ?> <span class="badge badge-mute"><?= $L('settings.store_secret_saved') ?></span><?php endif; ?></label>
                    <input type="password" name="store_paypal_sandbox_secret" value="" autocomplete="new-password" placeholder="<?= $s['store_paypal_sandbox_secret'] !== '' ? '••••••••' : '' ?>">
                </div>
                <div class="field" style="margin-bottom:18px">
                    <label><?= $L('settings.store_paypal_webhook_id') ?></label>
                    <input type="text" name="store_paypal_sandbox_webhook_id" value="<?= View::e($s['store_paypal_sandbox_webhook_id']) ?>" autocomplete="off">
                </div>

                <!-- Live credentials -->
                <div class="pkg-section-title"><?= $L('settings.store_mode_live') ?><?php if ($s['store_paypal_mode'] === 'live'): ?> · <?= $L('settings.store_env_active') ?><?php endif; ?></div>
                <div class="field" style="margin-bottom:14px">
                    <label><?= $L('settings.store_paypal_client_id') ?></label>
                    <input type="text" name="store_paypal_live_client_id" value="<?= View::e($s['store_paypal_live_client_id']) ?>" autocomplete="off">
                </div>
                <div class="field" style="margin-bottom:14px">
                    <label><?= $L('settings.store_paypal_secret') ?><?php if ($s['store_paypal_live_secret'] !== ''): ?> <span class="badge badge-mute"><?= $L('settings.store_secret_saved') ?></span><?php endif; ?></label>
                    <input type="password" name="store_paypal_live_secret" value="" autocomplete="new-password" placeholder="<?= $s['store_paypal_live_secret'] !== '' ? '••••••••' : '' ?>">
                </div>
                <div class="field">
                    <label><?= $L('settings.store_paypal_webhook_id') ?></label>
                    <input type="text" name="store_paypal_live_webhook_id" value="<?= View::e($s['store_paypal_live_webhook_id']) ?>" autocomplete="off">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.store_paypal_webhook_hint') ?></p>
                </div>
            </div>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.store_fees') ?></div>
                <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.store_fees_hint') ?></p>
                <div class="form-row">
                    <div class="field"><label><?= $L('settings.store_fee_percent') ?></label><input type="text" name="store_fee_percent" value="<?= View::e($s['store_fee_percent']) ?>" placeholder="3.4"></div>
                    <div class="field"><label><?= $L('settings.store_fee_fixed') ?> (<?= View::e($s['store_currency']) ?>)</label><input type="text" name="store_fee_fixed" value="<?= View::e($s['store_fee_fixed']) ?>" placeholder="0.35"></div>
                </div>
                <div class="check" style="margin-top:8px"><input type="checkbox" name="store_pass_fee_to_buyer" id="store_pass_fee" <?= $s['store_pass_fee_to_buyer'] ? 'checked' : '' ?>><label for="store_pass_fee"><?= $L('settings.store_pass_fee') ?></label></div>
                <p class="hint" style="margin-top:6px;margin-bottom:0"><?= $L('settings.store_pass_fee_hint') ?></p>
            </div>

            <!-- Order lifecycle: auto-cancel unpaid orders (separate from fees). -->
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.store_orders') ?></div>
                <div class="field" style="max-width:280px">
                    <label><?= $L('settings.store_pending_cancel') ?></label>
                    <input type="number" name="store_pending_cancel_minutes" min="1" max="1440" value="<?= View::e($s['store_pending_cancel_minutes']) ?>">
                </div>
                <p class="hint" style="margin:6px 0 0"><?= $L('settings.store_pending_cancel_hint') ?></p>
                <div class="field" style="max-width:280px;margin-top:14px">
                    <label><?= $L('settings.cart_ttl') ?></label>
                    <input type="number" name="cart_ttl_minutes" min="1" max="1440" value="<?= View::e($s['cart_ttl_minutes']) ?>">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.cart_ttl_hint') ?></p>
                </div>
            </div>

            <!-- Discounts: a site-wide checkout reduction + buyer discount codes. -->
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.store_discounts') ?></div>

                <div class="check" style="margin-bottom:10px"><input type="checkbox" name="store_checkout_discount_enabled" id="store_codisc_en" <?= $s['store_checkout_discount_enabled'] ? 'checked' : '' ?>><label for="store_codisc_en"><?= $L('settings.checkout_discount_enable') ?></label></div>
                <div class="field" style="max-width:280px">
                    <label><?= $L('settings.checkout_discount_percent') ?></label>
                    <input type="text" name="store_checkout_discount_percent" value="<?= View::e($s['store_checkout_discount_percent']) ?>" placeholder="10">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.checkout_discount_hint') ?></p>
                </div>

                <div class="check" style="margin:16px 0 10px"><input type="checkbox" name="store_codes_enabled" id="store_codes_en" <?= $s['store_codes_enabled'] ? 'checked' : '' ?>><label for="store_codes_en"><?= $L('settings.codes_enable') ?></label></div>
                <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.codes_hint') ?></p>

                <?php foreach (($s['discount_codes'] ?? []) as $i => $dc): ?>
                    <div class="card" style="margin-bottom:12px">
                        <input type="hidden" name="dc_id[<?= $i ?>]" value="<?= (int) $dc['id'] ?>">
                        <div class="form-row">
                            <div class="field"><label><?= $L('store.code_label') ?></label><input type="text" name="dc_code[<?= $i ?>]" value="<?= View::e($dc['code']) ?>" maxlength="48"></div>
                            <div class="field"><label>%</label><input type="text" name="dc_percent[<?= $i ?>]" value="<?= View::e(rtrim(rtrim(number_format((float) $dc['percent'], 2, '.', ''), '0'), '.')) ?>"></div>
                        </div>
                        <div class="form-row">
                            <div class="field"><label><?= $L('settings.code_max_uses') ?></label><input type="number" name="dc_max_uses[<?= $i ?>]" min="0" value="<?= (int) $dc['max_uses'] ?>"></div>
                            <div class="field"><label><?= $L('settings.code_used') ?></label><input type="text" value="<?= (int) $dc['used'] ?>" disabled></div>
                        </div>
                        <div class="form-row" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                            <div class="field">
                                <label><?= $L('settings.code_from') ?></label>
                                <div style="display:flex;gap:8px">
                                    <input type="date" name="dc_from_date[<?= $i ?>]" value="<?= View::e($dc['starts_at'] ? date('Y-m-d', strtotime((string) $dc['starts_at'])) : '') ?>" style="flex:1">
                                    <input type="text" name="dc_from_time[<?= $i ?>]" value="<?= View::e($dc['starts_at'] ? date('H:i', strtotime((string) $dc['starts_at'])) : '') ?>" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" title="24-hour HH:MM" style="width:90px">
                                </div>
                            </div>
                            <div class="field">
                                <label><?= $L('settings.code_to') ?></label>
                                <div style="display:flex;gap:8px">
                                    <input type="date" name="dc_to_date[<?= $i ?>]" value="<?= View::e($dc['ends_at'] ? date('Y-m-d', strtotime((string) $dc['ends_at'])) : '') ?>" style="flex:1">
                                    <input type="text" name="dc_to_time[<?= $i ?>]" value="<?= View::e($dc['ends_at'] ? date('H:i', strtotime((string) $dc['ends_at'])) : '') ?>" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" title="24-hour HH:MM" style="width:90px">
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;gap:18px;flex-wrap:wrap">
                            <div class="check"><input type="checkbox" name="dc_enabled[<?= $i ?>]" value="1" id="dc_en_<?= $i ?>" <?= (int) $dc['enabled'] ? 'checked' : '' ?>><label for="dc_en_<?= $i ?>"><?= $L('common.status') ?></label></div>
                            <div class="check"><input type="checkbox" name="dc_remove[<?= $i ?>]" value="1" id="dc_rm_<?= $i ?>"><label for="dc_rm_<?= $i ?>"><?= $L('common.delete') ?></label></div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="card">
                    <div class="card-title"><?= $L('settings.code_new') ?></div>
                    <div class="form-row">
                        <div class="field"><label><?= $L('store.code_label') ?></label><input type="text" name="dc_new_code" maxlength="48" placeholder="SUMMER20"></div>
                        <div class="field"><label>%</label><input type="text" name="dc_new_percent" placeholder="20"></div>
                    </div>
                    <div class="field"><label><?= $L('settings.code_max_uses') ?></label><input type="number" name="dc_new_max_uses" min="0" value="0"></div>
                    <div class="form-row" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                        <div class="field">
                            <label><?= $L('settings.code_from') ?></label>
                            <div style="display:flex;gap:8px">
                                <input type="date" name="dc_new_from_date" style="flex:1">
                                <input type="text" name="dc_new_from_time" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" title="24-hour HH:MM" style="width:90px">
                            </div>
                        </div>
                        <div class="field">
                            <label><?= $L('settings.code_to') ?></label>
                            <div style="display:flex;gap:8px">
                                <input type="date" name="dc_new_to_date" style="flex:1">
                                <input type="text" name="dc_new_to_time" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" title="24-hour HH:MM" style="width:90px">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.store_donations') ?></div>
                <div class="check" style="margin-bottom:10px"><input type="checkbox" name="store_donations_enabled" id="store_don_en" <?= $s['store_donations_enabled'] ? 'checked' : '' ?>><label for="store_don_en"><?= $L('settings.store_donations_enable') ?></label></div>
                <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.store_donations_hint') ?></p>
                <div class="form-row">
                    <div class="field"><label><?= $L('settings.store_donation_default') ?> (<?= View::e($s['store_currency']) ?>)</label><input type="text" name="store_donation_default" value="<?= View::e($s['store_donation_default']) ?>" placeholder="1.00"></div>
                    <div class="field"><label><?= $L('settings.store_donation_min') ?> (<?= View::e($s['store_currency']) ?>)</label><input type="text" name="store_donation_min" value="<?= View::e($s['store_donation_min']) ?>" placeholder="1.00"></div>
                </div>
            </div>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.store_email') ?></div>
                <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.store_email_hint') ?></p>
                <div class="form-row">
                    <div class="field"><label><?= $L('settings.mail_from') ?></label><input type="text" name="mail_from" value="<?= View::e($s['mail_from']) ?>" placeholder="no-reply@example.com"></div>
                    <div class="field"><label><?= $L('settings.mail_from_name') ?></label><input type="text" name="mail_from_name" value="<?= View::e($s['mail_from_name']) ?>" placeholder="<?= View::e($s['site_name']) ?>"></div>
                </div>
                <div class="form-row">
                    <div class="field"><label><?= $L('settings.mail_smtp_host') ?></label><input type="text" name="mail_smtp_host" value="<?= View::e($s['mail_smtp_host']) ?>" placeholder="smtp.gmail.com"></div>
                    <div class="field"><label><?= $L('settings.mail_smtp_port') ?></label><input type="number" name="mail_smtp_port" value="<?= View::e($s['mail_smtp_port']) ?>" min="1" max="65535"></div>
                    <div class="field"><label><?= $L('settings.mail_smtp_secure') ?></label>
                        <select name="mail_smtp_secure">
                            <option value="tls" <?= $s['mail_smtp_secure'] === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                            <option value="ssl" <?= $s['mail_smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="" <?= $s['mail_smtp_secure'] === '' ? 'selected' : '' ?>><?= $L('common.none') ?></option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field"><label><?= $L('settings.mail_smtp_user') ?></label><input type="text" name="mail_smtp_user" value="<?= View::e($s['mail_smtp_user']) ?>" autocomplete="off"></div>
                    <div class="field">
                        <label><?= $L('settings.mail_smtp_pass') ?><?php if ($s['mail_smtp_pass'] !== ''): ?> <span class="badge badge-active" style="font-size:10px"><?= $L('settings.mail_pass_saved') ?></span><?php endif; ?></label>
                        <input type="password" name="mail_smtp_pass" value="" autocomplete="new-password" placeholder="<?= $s['mail_smtp_pass'] !== '' ? '••••••••' : '' ?>">
                        <?php if ($s['mail_smtp_pass'] !== ''): ?><p class="hint" style="margin-top:4px"><?= $L('settings.mail_pass_keep') ?></p><?php endif; ?>
                    </div>
                </div>
                <p class="hint" style="margin-top:6px"><?= $L('settings.mail_smtp_hint') ?></p>
            </div>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.mail_test') ?></div>
                <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.mail_test_hint') ?></p>
                <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                    <div class="field" style="flex:1;min-width:220px;margin:0">
                        <label><?= $L('settings.mail_test_to') ?></label>
                        <input type="email" id="mail-test-to" placeholder="you@example.com">
                    </div>
                    <button type="button" class="btn" id="mail-test-btn"><?= $L('settings.mail_test_send') ?></button>
                </div>
                <p class="hint" id="mail-test-result" style="margin-top:8px;display:none"></p>
                <p class="hint" style="margin-top:6px"><?= $L('settings.mail_test_note') ?></p>
            </div>

            <div class="card pkg-editor collapsed" id="pkg-editor">
                <button type="button" class="pkg-editor-head" id="pkg-editor-toggle" aria-expanded="false">
                    <span class="card-title" style="margin:0"><?= $L('settings.store_packages') ?></span>
                    <svg class="pkg-editor-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="pkg-editor-body" id="pkg-editor-body">
                    <p class="hint" style="margin:6px 0 14px"><?= $L('settings.store_packages_hint') ?></p>

                    <?php
                    // Type sub-sections in the SAME order as the store front.
                    // Each is collapsible (collapsed by default) with its own
                    // "Add Package" button that pre-fills the correct type.
                    $pkgGroupDefs = [
                        'vip'       => $L('store.type_vip'),
                        'admin'     => $L('store.type_admin'),
                        'vip_admin' => $L('store.type_vip_admin'),
                    ];
                    $caret = '<svg class="pkg-group-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
                    ?>
                    <?php foreach ($pkgGroupDefs as $gid => $glabel): ?>
                        <div class="pkg-group collapsed" data-pkg-group="<?= View::e($gid) ?>">
                            <button type="button" class="pkg-group-head" data-pkg-toggle aria-expanded="false">
                                <span class="pkg-group-title"><?= $glabel ?> <span class="pkg-group-count" data-pkg-count="<?= View::e($gid) ?>"></span></span>
                                <?= $caret ?>
                            </button>
                            <div class="pkg-group-body">
                                <div class="pkg-list" data-pkg-list="<?= View::e($gid) ?>"></div>
                                <div class="pkg-group-empty muted" data-pkg-empty="<?= View::e($gid) ?>"><?= $L('store.section_empty') ?></div>
                                <button type="button" class="btn btn-sm pkg-add-in" data-pkg-add="<?= View::e($gid) ?>">+ <?= $L('settings.store_pkg_add') ?></button>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php
                    // CREDITS group (schwarper/cs2-store) — a 4th collapsible
                    // group in the same Packages card, next to VIP/ADMIN. Server-
                    // rendered rows (different fields than VIP packages), with
                    // its own add/remove handled by the inline script below.
                    $cpkgs = $s['store_credit_packages'] ?? [];
                    $cpkgCount = is_array($cpkgs) ? count(array_filter($cpkgs, static fn($c) => trim((string) ($c['name'] ?? '')) !== '')) : 0;
                    if (!$cpkgs) { $cpkgs = [['id' => '', 'name' => '', 'credits' => 0, 'net_cents' => 0, 'description' => '', 'enabled' => true]]; }
                    ?>
                    <div class="pkg-group collapsed" data-pkg-group="credits">
                        <button type="button" class="pkg-group-head" data-pkg-toggle aria-expanded="false">
                            <span class="pkg-group-title"><?= $L('store.type_credits') ?> <span class="pkg-group-count"><?= $cpkgCount > 0 ? '(' . $cpkgCount . ')' : '' ?></span></span>
                            <?= $caret ?>
                        </button>
                        <div class="pkg-group-body">
                            <div id="cpkg-rows">
                                <?php foreach (array_values($cpkgs) as $ci => $cp):
                                    $priceStr = isset($cp['net_cents']) ? number_format(((int) $cp['net_cents']) / 100, 2, '.', '') : '0.00';
                                ?>
                                <div class="cpkg-row" data-cpkg-row style="border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px;margin-bottom:10px">
                                    <input type="hidden" name="cpkg_id[<?= $ci ?>]" value="<?= View::e($cp['id'] ?? '') ?>">
                                    <div class="form-row">
                                        <div class="field"><label><?= $L('settings.credit_pkg_name') ?></label><input type="text" name="cpkg_name[<?= $ci ?>]" value="<?= View::e($cp['name'] ?? '') ?>" placeholder="1000 Credits"></div>
                                        <div class="field"><label><?= $L('settings.credit_pkg_credits') ?></label><input type="number" name="cpkg_credits[<?= $ci ?>]" min="0" value="<?= (int) ($cp['credits'] ?? 0) ?>"></div>
                                        <div class="field"><label><?= $L('settings.credit_pkg_price') ?> (<?= View::e($s['store_currency']) ?>)</label><input type="text" name="cpkg_price[<?= $ci ?>]" value="<?= View::e($priceStr) ?>" placeholder="5.00"></div>
                                    </div>
                                    <div class="form-row">
                                        <div class="field" style="flex:2"><label><?= $L('settings.credit_pkg_desc') ?></label><input type="text" name="cpkg_desc[<?= $ci ?>]" value="<?= View::e($cp['description'] ?? '') ?>"></div>
                                        <div class="field" style="flex:1"><label><?= $L('settings.credit_pkg_discount') ?></label><input type="number" name="cpkg_discount[<?= $ci ?>]" min="0" max="100" step="0.01" value="<?= View::e((string) ($cp['discount_percent'] ?? 0)) ?>"></div>
                                    </div>
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                                        <label class="inline-check"><input type="checkbox" name="cpkg_enabled[<?= $ci ?>]" value="1" <?= !empty($cp['enabled']) ? 'checked' : '' ?>> <?= $L('settings.credit_pkg_enabled') ?></label>
                                        <button type="button" class="btn btn-sm btn-danger" data-cpkg-remove><?= $L('common.delete') ?></button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm pkg-add-in" id="cpkg-add">+ <?= $L('settings.credit_pkg_add') ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function () {
            // Add/remove rows for the CREDITS group inside the Packages card.
            // Collapse is handled by the shared pkg-group toggle (data-pkg-toggle).
            var rows = document.getElementById('cpkg-rows');
            var addBtn = document.getElementById('cpkg-add');
            if (!rows || !addBtn) return;
            function nextIndex() {
                var max = -1;
                rows.querySelectorAll('input[name^="cpkg_name["]').forEach(function (el) {
                    var m = el.name.match(/\[(\d+)\]/); if (m) max = Math.max(max, parseInt(m[1], 10));
                });
                return max + 1;
            }
            addBtn.addEventListener('click', function () {
                var i = nextIndex();
                var tpl = rows.querySelector('[data-cpkg-row]');
                var div = tpl ? tpl.cloneNode(true) : null;
                if (!div) return;
                div.querySelectorAll('input').forEach(function (el) {
                    if (el.name) el.name = el.name.replace(/\[\d+\]/, '[' + i + ']');
                    if (el.type === 'checkbox') { el.checked = true; }
                    else if (el.type === 'hidden') { el.value = ''; }
                    else { el.value = ''; }
                });
                rows.appendChild(div);
            });
            rows.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-cpkg-remove]');
                if (!btn) return;
                var all = rows.querySelectorAll('[data-cpkg-row]');
                if (all.length <= 1) {
                    var r = btn.closest('[data-cpkg-row]');
                    r.querySelectorAll('input').forEach(function (el) {
                        if (el.type === 'checkbox') el.checked = false; else el.value = '';
                    });
                    return;
                }
                btn.closest('[data-cpkg-row]').remove();
            });
        })();
        </script>

        <?php
        // Data for the packages editor (rendered client-side so add/remove works).
        $pkgVipGroups = $s['store_vip_groups'] ?? [];
        $pkgServers = $s['store_servers'] ?? [];
                                                                                
        // Match the store's duration-first ordering before the editor loads.
        $pkgSorted = $s['store_packages'] ?? [];
        usort($pkgSorted, static function ($a, $b) {
            $byDays = (\App\Core\Store::sortDuration($a)) <=> (\App\Core\Store::sortDuration($b));
            if ($byDays !== 0) {
                return $byDays;
            }
            return \App\Core\Store::effectiveNetCents($a) <=> \App\Core\Store::effectiveNetCents($b);
        });
        ?>
        <script>
        (function () {
            var packages = <?= json_encode(array_map(static function ($p) {
                // Split the stored "from"/"to" moment into a date + 24h time pair
                // for the editor's plain inputs (no AM/PM locale ambiguity).
                $splitDt = static function (string $raw): array {
                    $ts = $raw !== '' ? strtotime($raw) : false;
                    return $ts !== false ? [date('Y-m-d', $ts), date('H:i', $ts)] : ['', ''];
                };
                [$discFromDate, $discFromTime] = $splitDt((string) ($p['discount_from'] ?? ''));
                [$discToDate, $discToTime] = $splitDt((string) ($p['discount_to'] ?? ''));
                return [
                    'id' => $p['id'],
                    'name' => $p['name'],
                    'vip_group' => $p['vip_group'],
                    'days' => $p['days'],
                    'admin_group_id' => (int) ($p['admin_group_id'] ?? 0),
                    'admin_immunity' => (int) ($p['admin_immunity'] ?? 0),
                    'admin_days' => (int) ($p['admin_days'] ?? 0),
                    'price' => \App\Core\Store::formatCents((int) $p['net_cents']),
                    // Effective (discount-applied) net in cents — used to sort the
                    // editor exactly like the store front does.
                    'effective_cents' => \App\Core\Store::effectiveNetCents($p),
                    'description' => $p['description'],
                    'server_id' => $p['server_id'],
                    'enabled' => (bool) $p['enabled'],
                    'discount_percent' => (float) ($p['discount_percent'] ?? 0),
                    'discount_from_date' => $discFromDate,
                    'discount_from_time' => $discFromTime,
                    'discount_to_date' => $discToDate,
                    'discount_to_time' => $discToTime,
                    'benefits_button' => !empty($p['benefits_button']),
                    'bonus_credits' => (int) ($p['bonus_credits'] ?? 0),
                ];
            }, $pkgSorted)) ?>;
            var groups = <?= json_encode(array_values($pkgVipGroups)) ?>;
            var servers = <?= json_encode(array_map(static fn($sv) => ['id' => (string) $sv['serverId'], 'label' => (string) $sv['serverIp'] . ':' . (string) $sv['port']], $pkgServers)) ?>;
            var adminGroups = <?= json_encode(array_map(static fn($g) => ['id' => (int) $g['id'], 'label' => (string) $g['name']], $s['store_admin_groups'] ?? [])) ?>;
            var L = {
                name: <?= json_encode(Lang::t('settings.store_pkg_name')) ?>,
                group: <?= json_encode(Lang::t('settings.store_pkg_group')) ?>,
                days: <?= json_encode(Lang::t('settings.store_pkg_days')) ?>,
                price: <?= json_encode(Lang::t('settings.store_pkg_price')) ?>,
                desc: <?= json_encode(Lang::t('settings.store_pkg_desc')) ?>,
                server: <?= json_encode(Lang::t('settings.store_pkg_server')) ?>,
                allServers: <?= json_encode(Lang::t('common.all')) ?>,
                enabled: <?= json_encode(Lang::t('settings.store_pkg_enabled')) ?>,
                remove: <?= json_encode(Lang::t('common.delete')) ?>,
                daysPerm: <?= json_encode(Lang::t('contests.prize_days_tip')) ?>,
                adminGroup: <?= json_encode(Lang::t('settings.store_pkg_admin_group')) ?>,
                adminNone: <?= json_encode(Lang::t('settings.store_pkg_admin_none')) ?>,
                adminImmunity: <?= json_encode(Lang::t('settings.store_pkg_admin_immunity')) ?>,
                adminDays: <?= json_encode(Lang::t('settings.store_pkg_admin_days')) ?>,
                vipNone: <?= json_encode(Lang::t('settings.store_pkg_vip_none')) ?>,
                vipSection: <?= json_encode(Lang::t('settings.store_pkg_vip_section')) ?>,
                adminSection: <?= json_encode(Lang::t('settings.store_pkg_admin_section')) ?>,
                edit: <?= json_encode(Lang::t('common.edit')) ?>,
                done: <?= json_encode(Lang::t('settings.store_pkg_done')) ?>,
                newPkg: <?= json_encode(Lang::t('settings.store_pkg_add')) ?>,
                typeVip: <?= json_encode(Lang::t('store.type_vip')) ?>,
                typeAdmin: <?= json_encode(Lang::t('store.type_admin')) ?>,
                typeBoth: <?= json_encode(Lang::t('store.type_vip_admin')) ?>,
                disabledTag: <?= json_encode(Lang::t('common.inactive')) ?>,
                delConfirm: <?= json_encode(Lang::t('settings.store_pkg_delete_confirm')) ?>,
                daysTpl: <?= json_encode(Lang::t('discord.days_n', ['__N__'])) ?>,
                permanent: <?= json_encode(Lang::t('common.permanent')) ?>,
                currency: <?= json_encode($s['store_currency']) ?>,
                discount: <?= json_encode(Lang::t('settings.store_pkg_discount')) ?>,
                discountFrom: <?= json_encode(Lang::t('settings.store_pkg_discount_from')) ?>,
                discountTo: <?= json_encode(Lang::t('settings.store_pkg_discount_to')) ?>,
                benefitsBtn: <?= json_encode(Lang::t('settings.store_pkg_benefits')) ?>,
                bonusSection: <?= json_encode(Lang::t('settings.store_pkg_bonus_section')) ?>,
                bonusCreditsOn: <?= json_encode(Lang::t('settings.store_pkg_bonus_credits_on')) ?>,
                bonusCredits: <?= json_encode(Lang::t('settings.store_pkg_bonus_credits')) ?>
            };
            // Per-type lists (VIP / ADMIN / VIP+ADMIN), mirroring the store front.
            var lists = {
                vip: document.querySelector('[data-pkg-list="vip"]'),
                vip_admin: document.querySelector('[data-pkg-list="vip_admin"]'),
                admin: document.querySelector('[data-pkg-list="admin"]')
            };
            if (!lists.vip) return;

            function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

            // Classify a row by what it currently grants: vip | admin | vip_admin.
            function pkgType(row) {
                var vip = (row.querySelector('[name^="pkg_group"]') || {}).value || '';
                var adminSel = row.querySelector('[name^="pkg_admin_group"]');
                var hasAdmin = adminSel && (parseInt(adminSel.value, 10) || 0) > 0;
                var hasVip = vip !== '';
                if (hasVip && hasAdmin) return 'vip_admin';
                if (hasAdmin) return 'admin';
                return 'vip';
            }

            // Show/hide the "no packages" note per group based on its row count.
            function refreshEmptyStates() {
                ['vip', 'vip_admin', 'admin'].forEach(function (t) {
                    var n = lists[t].children.length;
                    var note = document.querySelector('[data-pkg-empty="' + t + '"]');
                    if (note) note.style.display = n ? 'none' : '';
                    var count = document.querySelector('[data-pkg-count="' + t + '"]');
                    if (count) count.textContent = n ? '(' + n + ')' : '';
                });
            }

            // Move a row into the list matching its current type (called on build
            // and whenever the VIP/admin selection changes).
            function placeRow(row) {
                var t = pkgType(row);
                if (row.parentNode !== lists[t]) {
                    lists[t].appendChild(row);
                }
                refreshEmptyStates();
            }

            function groupControl(i, val) {
                // VIP is optional: an empty value means "no VIP" (admin-only pkg).
                var noneSel = (!val || val === '') ? ' selected' : '';
                if (groups.length) {
                    var opts = '<option value=""' + noneSel + '>' + esc(L.vipNone) + '</option>';
                    opts += groups.map(function (g) {
                        return '<option value="' + esc(g) + '"' + (g === val ? ' selected' : '') + '>' + esc(g) + '</option>';
                    }).join('');
                    // Allow a stored group that is no longer in the list.
                    if (val && groups.indexOf(val) === -1) {
                        opts += '<option value="' + esc(val) + '" selected>' + esc(val) + '</option>';
                    }
                    return '<select name="pkg_group[' + i + ']">' + opts + '</select>';
                }
                return '<input type="text" name="pkg_group[' + i + ']" value="' + esc(val) + '" placeholder="' + esc(L.vipNone) + '">';
            }

            function serverControl(i, val) {
                var opts = '<option value="all"' + (val === 'all' || !val ? ' selected' : '') + '>' + esc(L.allServers) + '</option>';
                opts += servers.map(function (sv) {
                    return '<option value="' + esc(sv.id) + '"' + (sv.id === String(val) ? ' selected' : '') + '>' + esc(sv.label) + '</option>';
                }).join('');
                return '<select name="pkg_server[' + i + ']">' + opts + '</select>';
            }

            function adminGroupControl(i, val) {
                var cur = parseInt(val, 10) || 0;
                var opts = '<option value="0"' + (cur <= 0 ? ' selected' : '') + '>' + esc(L.adminNone) + '</option>';
                opts += adminGroups.map(function (g) {
                    return '<option value="' + g.id + '"' + (g.id === cur ? ' selected' : '') + '>' + esc(g.label) + '</option>';
                }).join('');
                return '<select name="pkg_admin_group[' + i + ']">' + opts + '</select>';
            }

            // Build the collapsed summary text (name — TYPE · price) from the
            // row's current field values.
            function refreshSummary(row) {
                var name = (row.querySelector('[name^="pkg_name"]') || {}).value || '';
                var price = (row.querySelector('[name^="pkg_price"]') || {}).value || '';
                var vip = (row.querySelector('[name^="pkg_group"]') || {}).value || '';
                var adminSel = row.querySelector('[name^="pkg_admin_group"]');
                var hasAdmin = adminSel && (parseInt(adminSel.value, 10) || 0) > 0;
                var hasVip = vip !== '';
                var type = (hasVip && hasAdmin) ? L.typeBoth : (hasAdmin ? L.typeAdmin : L.typeVip);
                var enabled = row.querySelector('[name^="pkg_enabled"]');
                var off = enabled && !enabled.checked;
                // Duration: VIP days, or admin days for an admin-only package.
                function durText(days) {
                    days = parseInt(days, 10) || 0;
                    return days > 0 ? L.daysTpl.replace('__N__', days) : L.permanent;
                }
                var vipDays = (row.querySelector('[name^="pkg_days"]') || {}).value;
                var adminDays = (row.querySelector('[name^="pkg_admin_days"]') || {}).value;
                var dur = hasVip ? durText(vipDays) : (hasAdmin ? durText(adminDays) : '');
                var titleEl = row.querySelector('.pkg-sum-name');
                var metaEl = row.querySelector('.pkg-sum-meta');
                if (titleEl) titleEl.textContent = name || L.newPkg;
                if (metaEl) {
                    metaEl.innerHTML = '<span class="pkg-sum-type">' + esc(type) + '</span>'
                        + (dur ? ' · ' + esc(dur) : '')
                        + (price ? ' · ' + esc(price) + ' ' + esc(L.currency) : '')
                        + (off ? ' · <span class="pkg-sum-off">' + esc(L.disabledTag) + '</span>' : '');
                }
            }

            var idx = 0;
            function addRow(p, startCollapsed, forceType) {
                p = p || {};
                var i = idx++;
                var row = document.createElement('div');
                row.className = 'pkg-row card' + (startCollapsed ? ' collapsed' : '');
                row.innerHTML =
                    '<input type="hidden" name="pkg_id[' + i + ']" value="' + esc(p.id || '') + '">' +
                    // ---- Collapsed summary header (name + type + price + actions) ----
                    '<div class="pkg-summary">' +
                        '<div class="pkg-sum-info">' +
                            '<span class="pkg-sum-name"></span>' +
                            '<span class="pkg-sum-meta muted"></span>' +
                        '</div>' +
                        '<div class="pkg-sum-actions">' +
                            '<button type="button" class="btn btn-sm pkg-edit">' + esc(L.edit) + '</button>' +
                            '<button type="button" class="btn btn-sm btn-danger pkg-del">' + esc(L.remove) + '</button>' +
                        '</div>' +
                    '</div>' +
                    // ---- Editable body (hidden when collapsed) ----
                    '<div class="pkg-body">' +
                        '<div class="form-row">' +
                            '<div class="field"><label>' + esc(L.name) + '</label><input type="text" name="pkg_name[' + i + ']" value="' + esc(p.name) + '" required></div>' +
                            '<div class="field"><label>' + esc(L.price) + '</label><input type="text" name="pkg_price[' + i + ']" value="' + esc(p.price || '') + '" placeholder="10.00"></div>' +
                            '<div class="field"><label>' + esc(L.server) + '</label>' + serverControl(i, p.server_id || 'all') + '</div>' +
                        '</div>' +
                        '<div class="pkg-section-title">' + esc(L.vipSection) + '</div>' +
                        '<div class="form-row">' +
                            '<div class="field"><label>' + esc(L.group) + '</label>' + groupControl(i, p.vip_group || '') + '</div>' +
                            '<div class="field"><label>' + esc(L.days) + '</label><input type="number" name="pkg_days[' + i + ']" value="' + (parseInt(p.days,10)||0) + '" min="0" title="' + esc(L.daysPerm) + '"></div>' +
                        '</div>' +
                        '<div class="pkg-section-title">' + esc(L.adminSection) + '</div>' +
                        '<div class="form-row">' +
                            '<div class="field"><label>' + esc(L.adminGroup) + '</label>' + adminGroupControl(i, p.admin_group_id || 0) + '</div>' +
                            '<div class="field"><label>' + esc(L.adminImmunity) + '</label><input type="number" name="pkg_admin_immunity[' + i + ']" value="' + (parseInt(p.admin_immunity,10)||0) + '" min="0"></div>' +
                            '<div class="field"><label>' + esc(L.adminDays) + '</label><input type="number" name="pkg_admin_days[' + i + ']" value="' + (parseInt(p.admin_days,10)||0) + '" min="0" title="' + esc(L.daysPerm) + '"></div>' +
                        '</div>' +
                        // ---- Bonus store credits (cs2-store): optional extra on a VIP/admin package ----
                        '<div class="pkg-section-title">' + esc(L.bonusSection) + '</div>' +
                        '<div class="form-row" style="align-items:center">' +
                            '<label class="check" style="margin-top:22px"><input type="checkbox" class="js-bonus-credits-on" name="pkg_bonus_credits_on[' + i + ']" value="1"' + ((parseInt(p.bonus_credits,10)||0) > 0 ? ' checked' : '') + '> <span>' + esc(L.bonusCreditsOn) + '</span></label>' +
                            '<div class="field"><label>' + esc(L.bonusCredits) + '</label><input type="number" class="js-bonus-credits-amt" name="pkg_bonus_credits[' + i + ']" value="' + (parseInt(p.bonus_credits,10)||0) + '" min="0"></div>' +
                        '</div>' +
                        '<div class="field"><label>' + esc(L.desc) + '</label><input type="text" name="pkg_desc[' + i + ']" value="' + esc(p.description) + '"></div>' +
                        '<div class="form-row" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">' +
                            '<div class="field"><label>' + esc(L.discount) + '</label><input type="number" name="pkg_discount[' + i + ']" value="' + (parseFloat(p.discount_percent)||0) + '" min="0" max="100" step="0.1"></div>' +
                            '<div class="field"><label>' + esc(L.discountFrom) + '</label><div style="display:flex;gap:8px">' +
                                '<input type="date" name="pkg_discount_from_date[' + i + ']" value="' + esc(p.discount_from_date || '') + '" style="flex:1">' +
                                '<input type="text" name="pkg_discount_from_time[' + i + ']" value="' + esc(p.discount_from_time || '') + '" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" title="24-hour HH:MM" style="width:90px"></div></div>' +
                            '<div class="field"><label>' + esc(L.discountTo) + '</label><div style="display:flex;gap:8px">' +
                                '<input type="date" name="pkg_discount_to_date[' + i + ']" value="' + esc(p.discount_to_date || '') + '" style="flex:1">' +
                                '<input type="text" name="pkg_discount_to_time[' + i + ']" value="' + esc(p.discount_to_time || '') + '" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" title="24-hour HH:MM" style="width:90px"></div></div>' +
                        '</div>' +
                        '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;gap:14px;flex-wrap:wrap">' +
                            '<div style="display:flex;gap:16px;flex-wrap:wrap">' +
                                '<label class="check"><input type="checkbox" name="pkg_enabled[' + i + ']" value="1"' + (p.enabled === false ? '' : ' checked') + '> <span>' + esc(L.enabled) + '</span></label>' +
                                '<label class="check"><input type="checkbox" name="pkg_benefits[' + i + ']" value="1"' + (p.benefits_button ? ' checked' : '') + '> <span>' + esc(L.benefitsBtn) + '</span></label>' +
                            '</div>' +
                            '<button type="button" class="btn btn-sm btn-primary pkg-done">' + esc(L.done) + '</button>' +
                        '</div>' +
                    '</div>';

                row.querySelector('.pkg-del').addEventListener('click', function () { if (window.confirm(L.delConfirm)) { row.remove(); refreshEmptyStates(); } });
                row.querySelector('.pkg-edit').addEventListener('click', function () { row.classList.remove('collapsed'); });
                row.querySelector('.pkg-done').addEventListener('click', function () { refreshSummary(row); row.classList.add('collapsed'); });
                // Keep the summary in sync while editing; re-file the row into its
                // matching type section when the VIP/admin selection changes.
                row.querySelectorAll('input, select').forEach(function (el) {
                    el.addEventListener('input', function () { refreshSummary(row); });
                    el.addEventListener('change', function () { refreshSummary(row); placeRow(row); });
                });
                lists.vip.appendChild(row); // temporary home; placeRow moves it
                if (forceType && lists[forceType]) {
                    lists[forceType].appendChild(row);
                    refreshEmptyStates();
                } else {
                    placeRow(row);
                }
                refreshSummary(row);
                return row;
            }

            // Sort by price ascending, then by duration (permanent last), so the
            // editor mirrors the store front's ordering within each type section.
            function pkgSortDays(p) {
                var hasAdmin = (parseInt(p.admin_group_id, 10) || 0) > 0;
                var hasVip = (p.vip_group || '') !== '';
                var days = (hasAdmin && !hasVip) ? (parseInt(p.admin_days, 10) || 0) : (parseInt(p.days, 10) || 0);
                return days > 0 ? days : Number.MAX_SAFE_INTEGER; // permanent last
            }
            packages.sort(function (a, b) {
                // Duration first (group by days; permanent last), then price asc.
                var da = pkgSortDays(a), db = pkgSortDays(b);
                if (da !== db) return da - db;
                var pa = parseInt(a.effective_cents, 10);
                var pb = parseInt(b.effective_cents, 10);
                if (isNaN(pa)) pa = Math.round((parseFloat(String(a.price).replace(',', '.')) || 0) * 100);
                if (isNaN(pb)) pb = Math.round((parseFloat(String(b.price).replace(',', '.')) || 0) * 100);
                return pa - pb;
            });

            // Existing packages start collapsed; new ones open for editing.
            packages.forEach(function (p) { addRow(p, true); });

            // Per-section "Add Package": seed sensible defaults so the new row
            // lands in (and matches) the section it was added from.
            var firstAdmin = adminGroups.length ? adminGroups[0].id : 0;
            var firstVip = groups.length ? groups[0] : '';
            function seedForType(t) {
                if (t === 'admin')     return { enabled: true, admin_group_id: firstAdmin };
                if (t === 'vip_admin') return { enabled: true, vip_group: firstVip, admin_group_id: firstAdmin };
                return { enabled: true }; // vip
            }
            document.querySelectorAll('[data-pkg-add]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var t = btn.getAttribute('data-pkg-add');
                    var group = btn.closest('.pkg-group');
                    // Make sure the section is expanded so the new (open) row is visible.
                    if (group) { group.classList.remove('collapsed'); var h = group.querySelector('[data-pkg-toggle]'); if (h) h.setAttribute('aria-expanded', 'true'); }
                    addRow(seedForType(t), false, t);
                });
            });

            // Collapse/expand each type section (collapsed by default).
            document.querySelectorAll('.pkg-group [data-pkg-toggle]').forEach(function (head) {
                head.addEventListener('click', function () {
                    var group = head.closest('.pkg-group');
                    if (!group) return;
                    var collapsed = group.classList.toggle('collapsed');
                    head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                });
            });

            // Collapse/expand the whole packages editor.
            var editor = document.getElementById('pkg-editor');
            var toggle = document.getElementById('pkg-editor-toggle');
            if (editor && toggle) {
                toggle.addEventListener('click', function () {
                    var collapsed = editor.classList.toggle('collapsed');
                    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                });
            }
        })();

        // Reveal/hide the per-feature test toggles live with the Test Mode switch.
        (function () {
            var master = document.getElementById('test_mode');
            var box = document.getElementById('test-features');
            if (!master || !box) return;
            master.addEventListener('change', function () {
                box.style.display = master.checked ? '' : 'none';
            });
        })();

        // SMTP / mail test button (AJAX, so it doesn't submit the whole form).
        (function () {
            var btn = document.getElementById('mail-test-btn');
            var to = document.getElementById('mail-test-to');
            var out = document.getElementById('mail-test-result');
            if (!btn || !to || !out) return;
            btn.addEventListener('click', function () {
                var addr = (to.value || '').trim();
                out.style.display = 'block';
                out.textContent = <?= json_encode(Lang::t('settings.mail_test_sending')) ?>;
                var body = 'csrf=' + encodeURIComponent(window.CS2_CSRF || '') + '&to=' + encodeURIComponent(addr);
                fetch((window.CS2_BASE || '') + '/settings/test-mail', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' }, body: body
                }).then(function (r) { return r.json(); }).then(function (j) {
                    out.textContent = j.message || (j.ok ? 'OK' : 'Failed');
                    out.style.color = j.ok ? '#21e065' : '#ff4d5e';
                }).catch(function () {
                    out.textContent = <?= json_encode(Lang::t('settings.mail_test_failed')) ?>;
                    out.style.color = '#ff4d5e';
                });
            });
        })();
        </script>

        <!-- General (Steam + session + test mode) — same tab as appearance -->
        <div data-tabpanel="general" style="display:none">
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.steam2') ?></div>
                <div class="field" style="margin-bottom:8px">
                    <label><?= $L('settings.steam_api_key') ?></label>
                    <input type="text" name="steam_api_key" value="<?= View::e($s['steam_api_key']) ?>" placeholder="XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" autocomplete="off">
                    <p class="hint" style="margin-top:6px">
                        <?= $L('settings.steam_api_key_hint') ?>
                        <a href="https://steamcommunity.com/dev/apikey" target="_blank" rel="noopener">steamcommunity.com/dev/apikey</a>
                    </p>
                </div>
            </div>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.session2') ?></div>
                <div class="field">
                    <label><?= $L('settings.login_days') ?></label>
                    <input type="number" name="login_days" min="0" max="3650" value="<?= View::e($s['login_days']) ?>" style="max-width:220px">
                    <p class="hint" style="margin-top:6px"><?= $L('settings.login_days_hint') ?></p>
                </div>
            </div>

            <div class="card">
                <div class="card-title"><?= $L('settings.test_mode') ?></div>
                <div class="check" style="margin-bottom:6px"><input type="checkbox" name="test_mode" id="test_mode" <?= !empty($s['test_mode']) ? 'checked' : '' ?>><label for="test_mode"><?= $L('settings.test_mode_enable') ?></label></div>
                <p class="hint" style="margin-bottom:0"><?= $L('settings.test_mode_hint') ?></p>

                <!-- Individual test features (shown only while Test Mode is on). -->
                <div id="test-features" class="test-features" style="<?= !empty($s['test_mode']) ? '' : 'display:none' ?>">
                    <div class="pkg-section-title" style="margin-top:14px"><?= $L('settings.test_features') ?></div>
                    <?php foreach (\App\Core\Store::testFeatures() as $fid => $feat): ?>
                        <?php /* Each feature defaults OFF: enabling Test Mode reveals them all but activates none — tick only the ones you need. */ ?>
                        <?php $on = \App\Core\Settings::bool('test_feat_' . $fid, false); ?>
                        <div class="check" style="margin-bottom:4px">
                            <input type="checkbox" name="test_feat_<?= View::e($fid) ?>" id="tf_<?= View::e($fid) ?>" <?= $on ? 'checked' : '' ?>>
                            <label for="tf_<?= View::e($fid) ?>"><?= $L($feat['label']) ?></label>
                        </div>
                        <p class="hint" style="margin:-2px 0 10px 26px"><?= $L($feat['hint']) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

                        
        <div data-tabpanel="discord" style="display:none">
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.discord2') ?></div>
                <div class="check" style="margin-bottom:14px"><input type="checkbox" name="discord_enabled" id="dc_en" <?= $s['discord_enabled'] ? 'checked' : '' ?>><label for="dc_en"><?= $L('settings.enable_discord') ?></label></div>
                <div class="field"><label><?= $L('settings.webhook_url') ?></label><input type="url" name="discord_webhook" value="<?= View::e($s['discord_webhook']) ?>" placeholder="https://discord.com/api/webhooks/..."></div>
                <p class="hint" style="margin-bottom:16px"><?= $L('settings.discord_hint') ?></p>
                <div class="check" style="margin-bottom:6px"><input type="checkbox" name="discord_log_all" id="dc_logall" <?= $s['discord_log_all'] ? 'checked' : '' ?>><label for="dc_logall"><?= $L('settings.discord_log_all') ?></label></div>
                <p class="hint" style="margin-bottom:16px"><?= $L('settings.discord_log_all_hint') ?></p>
                <!-- Test button submits its own form (see below) via the form attribute -->
                <button type="submit" form="discord-test-form" class="btn"><?= $L('settings.send_test') ?></button>
            </div>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.notify_types') ?></div>
                <p class="hint" style="margin:-4px 0 12px"><?= $L('settings.notify_types_hint') ?></p>
                <div class="form-row">
                    <?php foreach (\App\Core\Discord::CATEGORIES as $catId => $catLabel): ?>
                        <div class="check">
                            <input type="checkbox" name="discord_notify_<?= View::e($catId) ?>" id="dn_<?= View::e($catId) ?>" <?= !empty($s['discord_notify'][$catId]) ? 'checked' : '' ?>>
                            <label for="dn_<?= View::e($catId) ?>"><?= $L($catLabel) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('settings.community_links') ?></div>
                <div class="form-row">
                    <div class="field"><label><?= $L('settings.discord_invite') ?></label><input type="text" name="discord_invite" value="<?= View::e($s['discord_invite']) ?>" placeholder="e.g. AbC123 or https://discord.gg/AbC123"></div>
                    <div class="field"><label><?= $L('settings.forum_url') ?></label><input type="url" name="forum_url" value="<?= View::e($s['forum_url']) ?>" placeholder="https://forum.example.com"></div>
                </div>
                <p class="hint"><?= $L('settings.community_hint') ?></p>
            </div>

        </div>

        <div style="display:flex;gap:10px;margin-top:20px" id="settings-save-row">
            <button type="submit" class="btn btn-primary"><?= $L('common.save') ?></button>
        </div>
    </form>

    <!-- Separate form for the Discord test (referenced by the button above via form=) -->
    <form method="post" action="/settings/test-discord" id="discord-test-form">
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="discord_webhook" id="discord-test-webhook" value="">
    </form>

    <!-- Benefits page (its own POST — outside the main settings form). -->
    <div data-tabpanel="benefits" style="display:none">
        <div class="card" style="margin-top:16px;margin-bottom:18px">
            <div class="card-title"><?= $L('settings.tab_benefits') ?></div>
            <p class="hint" style="margin:-4px 0 14px"><?= $L('settings.benefits_items_hint') ?></p>
            <form method="post" action="/benefits/save" id="benefits-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="check" style="margin-bottom:6px"><input type="checkbox" name="benefits_enabled" id="benefits_en" <?= $s['benefits_enabled'] ? 'checked' : '' ?>><label for="benefits_en"><?= $L('settings.benefits_enable') ?></label></div>
                <p class="hint" style="margin:-2px 0 16px"><?= $L('settings.benefits_hint') ?></p>
                <div id="ben-rows">
                    <?php foreach ($s['benefits_items'] as $bi => $b): ?>
                    <?php
                        $bIcon = trim((string) ($b['icon'] ?? ''));
                        $bIsUpload = (bool) preg_match('/^ben_[a-f0-9]{12}\.(png|jpe?g|gif|webp|svg)$/i', $bIcon);
                        $bPreview = $bIsUpload ? url('/assets/img/benefits/uploads/' . $bIcon) : '';
                    ?>
                    <div class="ben-row card collapsed" data-ben>
                        <div class="ben-row-head" title="<?= $L('settings.benefits_drag') ?>" draggable="true">
                            <span class="ben-drag" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 6h.01M8 12h.01M8 18h.01M16 6h.01M16 12h.01M16 18h.01"/></svg>
                            </span>
                            <button type="button" class="ben-row-toggle" data-ben-toggle>
                                <span class="ben-row-title"><?= View::e(trim((string) $b['category']) !== '' ? $b['category'] : Lang::t('settings.benefits_add')) ?></span>
                                <svg class="ben-row-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                            </button>
                        </div>
                        <div class="ben-row-body">
                        <div class="ben-grid">
                            <div class="field ben-cat"><label><?= $L('settings.benefits_category') ?></label><input type="text" name="ben_category[<?= $bi ?>]" value="<?= View::e($b['category']) ?>" placeholder="VIP BRONZE"></div>
                            <div class="field ben-icon">
                                <label><?= $L('settings.benefits_icon') ?></label>
                                <div class="ben-icon-row">
                                    <?php if ($bPreview !== ''): ?><img class="ben-icon-preview" src="<?= View::e($bPreview) ?>" alt=""><?php endif; ?>
                                    <input type="text" name="ben_icon[<?= $bi ?>]" value="<?= View::e($bIcon) ?>" maxlength="191" placeholder="vip-gold / emoji">
                                </div>
                                <div class="ben-icon-actions">
                                    <label class="btn btn-sm ben-upload-btn"><?= $L('settings.benefits_icon_upload') ?><input type="file" name="ben_icon_file[<?= $bi ?>]" accept="image/*" hidden></label>
                                    <label class="check ben-reset"><input type="checkbox" name="ben_icon_reset[<?= $bi ?>]" value="1"> <span><?= $L('settings.benefits_icon_reset') ?></span></label>
                                </div>
                                <p class="hint" style="margin-top:4px"><?= $L('settings.benefits_icon_hint') ?></p>
                            </div>
                        </div>
                        <div class="field ben-desc"><label><?= $L('settings.benefits_desc') ?></label><textarea name="ben_desc[<?= $bi ?>]" rows="8"><?= View::e($b['description']) ?></textarea></div>
                        <div class="ben-row-foot">
                            <label class="check"><input type="checkbox" name="ben_enabled[<?= $bi ?>]" value="1" <?= $b['enabled'] ? 'checked' : '' ?>> <span><?= $L('settings.store_pkg_enabled') ?></span></label>
                            <button type="button" class="btn btn-sm btn-danger ben-del">&times;</button>
                        </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:10px;margin-top:10px">
                    <button type="button" class="btn btn-sm" id="ben-add">+ <?= $L('settings.benefits_add') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm"><?= $L('common.save') ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php if (App\Core\Auth::isRoot()): ?>
    <!-- Updates tab (root only). Its forms live OUTSIDE the main settings form. -->
    <script>
    (function () {
        var rows = document.getElementById('ben-rows');
        if (!rows) return;
        var idx = rows.children.length;
        var L = {
            category: <?= json_encode(Lang::t('settings.benefits_category')) ?>,
            icon: <?= json_encode(Lang::t('settings.benefits_icon')) ?>,
            iconHint: <?= json_encode(Lang::t('settings.benefits_icon_hint')) ?>,
            upload: <?= json_encode(Lang::t('settings.benefits_icon_upload')) ?>,
            reset: <?= json_encode(Lang::t('settings.benefits_icon_reset')) ?>,
            desc: <?= json_encode(Lang::t('settings.benefits_desc')) ?>,
            enabled: <?= json_encode(Lang::t('settings.store_pkg_enabled')) ?>,
            add: <?= json_encode(Lang::t('settings.benefits_add')) ?>,
            drag: <?= json_encode(Lang::t('settings.benefits_drag')) ?>
        };
        function esc(s) { return String(s==null?'':s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
        var caret = '<svg class="ben-row-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
        document.getElementById('ben-add').addEventListener('click', function () {
            var i = idx++;
            var d = document.createElement('div');
            d.className = 'ben-row card'; // new rows start expanded for editing
            d.setAttribute('data-ben', '');
            d.innerHTML =
                '<div class="ben-row-head" title="' + esc(L.drag) + '" draggable="true">' +
                    '<span class="ben-drag" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 6h.01M8 12h.01M8 18h.01M16 6h.01M16 12h.01M16 18h.01"/></svg></span>' +
                    '<button type="button" class="ben-row-toggle" data-ben-toggle>' +
                        '<span class="ben-row-title">' + esc(L.add) + '</span>' + caret +
                    '</button>' +
                '</div>' +
                '<div class="ben-row-body">' +
                '<div class="ben-grid">' +
                    '<div class="field ben-cat"><label>' + esc(L.category) + '</label><input type="text" name="ben_category[' + i + ']" placeholder="VIP BRONZE"></div>' +
                    '<div class="field ben-icon">' +
                        '<label>' + esc(L.icon) + '</label>' +
                        '<div class="ben-icon-row"><input type="text" name="ben_icon[' + i + ']" maxlength="191" placeholder="vip-gold / emoji"></div>' +
                        '<div class="ben-icon-actions">' +
                            '<label class="btn btn-sm ben-upload-btn">' + esc(L.upload) + '<input type="file" name="ben_icon_file[' + i + ']" accept="image/*" hidden></label>' +
                            '<label class="check ben-reset"><input type="checkbox" name="ben_icon_reset[' + i + ']" value="1"> <span>' + esc(L.reset) + '</span></label>' +
                        '</div>' +
                        '<p class="hint" style="margin-top:4px">' + esc(L.iconHint) + '</p>' +
                    '</div>' +
                '</div>' +
                '<div class="field ben-desc"><label>' + esc(L.desc) + '</label><textarea name="ben_desc[' + i + ']" rows="8"></textarea></div>' +
                '<div class="ben-row-foot">' +
                    '<label class="check"><input type="checkbox" name="ben_enabled[' + i + ']" value="1" checked> <span>' + esc(L.enabled) + '</span></label>' +
                    '<button type="button" class="btn btn-sm btn-danger ben-del">&times;</button>' +
                '</div>' +
                '</div>';
            rows.appendChild(d);
        });
        rows.addEventListener('click', function (e) {
            var del = e.target.closest('.ben-del');
            if (del) { e.preventDefault(); del.closest('[data-ben]').remove(); return; }
            var tog = e.target.closest('.ben-row-toggle');
            if (tog) { tog.closest('[data-ben]').classList.toggle('collapsed'); }
        });

        // Drag-and-drop ordering: dragging a row's handle reorders the cards,
        // and the public page honors that order (saved as sort_order on save).
        var dragRow = null;
        // A visible drop indicator line that shows exactly where the card lands.
        var dropLine = document.createElement('div');
        dropLine.className = 'ben-drop-line';
        function clearDropLine() { if (dropLine.parentNode) dropLine.parentNode.removeChild(dropLine); }
        rows.addEventListener('dragstart', function (e) {
            var head = e.target.closest('.ben-row-head');
            if (!head) { e.preventDefault(); return; }
            dragRow = head.closest('[data-ben]');
            if (dragRow) {
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', ''); } catch (x) {}
                // Delay the class so the drag image isn't the faded version.
                setTimeout(function () { if (dragRow) dragRow.classList.add('ben-dragging'); }, 0);
                rows.classList.add('ben-reordering');
            }
        });
        rows.addEventListener('dragend', function () {
            if (dragRow) dragRow.classList.remove('ben-dragging');
            rows.classList.remove('ben-reordering');
            clearDropLine();
            dragRow = null;
        });
        rows.addEventListener('dragover', function (e) {
            if (!dragRow) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var over = e.target.closest('[data-ben]');
            if (!over || over === dragRow) { return; }
            var r = over.getBoundingClientRect();
            var after = (e.clientY - r.top) > r.height / 2;
            rows.insertBefore(dropLine, after ? over.nextSibling : over);
        });
        rows.addEventListener('drop', function (e) {
            if (!dragRow) return;
            e.preventDefault();
            if (dropLine.parentNode) {
                rows.insertBefore(dragRow, dropLine);
            }
            clearDropLine();
        });
        // Keep the collapsed-row title in sync with the category field.
        rows.addEventListener('input', function (e) {
            var inp = e.target.closest('input[name^="ben_category"]');
            if (!inp) return;
            var row = inp.closest('[data-ben]');
            var title = row && row.querySelector('.ben-row-title');
            if (title) title.textContent = inp.value.trim() || L.add;
        });
        // Show the chosen file name on the upload button for feedback.
        rows.addEventListener('change', function (e) {
            var f = e.target.closest('input[type="file"]');
            if (f && f.files && f.files.length) {
                var lbl = f.closest('.ben-upload-btn');
                if (lbl && lbl.childNodes[0]) lbl.childNodes[0].textContent = f.files[0].name + ' ';
            }
        });
    })();
    </script>

    <div data-tabpanel="updates" style="display:none">
        <?php
        $u        = $update ?? ['current' => \App\Core\Version::current(), 'codename' => \App\Core\Version::codename(), 'latest' => null, 'hasUpdate' => false];
        $upLog    = $_SESSION['update_log'] ?? [];
        unset($_SESSION['update_log']);
        ?>
        <?php if ($upLog): ?>
            <div class="card" style="margin-bottom:18px">
                <div class="card-title"><?= $L('update.result') ?></div>
                <ul class="update-log"><?php foreach ($upLog as $line): ?><li><?= View::e($line) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="card update-hero <?= !empty($u['hasUpdate']) ? 'has-update' : 'up-to-date' ?>" id="update-hero">
            <div class="update-badge" id="update-badge">
                <?php if (!empty($u['hasUpdate'])): ?>
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
                <?php else: ?>
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                <?php endif; ?>
            </div>
            <div class="update-info">
                <div class="update-current">
                    <span class="muted"><?= $L('update.current') ?></span>
                    <strong>v<?= View::e($u['current']) ?></strong>
                </div>
                <div class="update-next" id="update-next" style="align-items:center;gap:18px;<?= (!empty($u['hasUpdate']) && $u['latest']) ? 'display:flex' : 'display:none' ?>">
                    <div class="update-arrow">→</div>
                    <div>
                        <p class="hint"><?= $L('update.latest') ?></p>
                        <strong class="accent" id="update-latest-ver">v<?= View::e($u['latest']['version'] ?? '') ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($u['hasUpdate']) && $u['latest']): ?>
            <div class="card" style="margin-top:18px">
                <div class="card-title"><?= $L('update.new_version') ?></div>
                <p class="muted" style="margin:0 0 16px">
                    <?= $L('update.new_intro') ?>
                    <a href="<?= View::e($u['latest']['url']) ?>" target="_blank" rel="noopener"><?= $L('update.view_changelog') ?> ↗</a>
                </p>

                <?php
                // Release notes from GitHub (title + body). Rendered as safe,
                // minimally-formatted HTML (escaped first, then light Markdown).
                $relName  = trim((string) ($u['latest']['release_name'] ?? ''));
                $relNotes = trim((string) ($u['latest']['release_notes'] ?? ''));
                if ($relName !== '' || $relNotes !== ''):
                ?>
                <div class="update-changelog">
                    <div class="changelog-head">
                        <span class="changelog-tag"><?= View::e($u['latest']['tag']) ?></span>
                        <?php if ($relName !== ''): ?><strong><?= View::e($relName) ?></strong><?php endif; ?>
                    </div>
                    <?php if ($relNotes !== ''): ?>
                        <div class="changelog-body"><?= App\Core\View::miniMarkdown($relNotes) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="update-warn">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 20h20L12 2z"/><path d="M12 9v5M12 17h.01"/></svg>
                    <span><?= $L('update.backup_warn') ?></span>
                </div>
                <div class="update-note">
                    <strong><?= $L('update.before_title') ?></strong>
                    <ul>
                        <li><?= $L('update.before_backup') ?></li>
                        <li><?= $L('update.before_config') ?></li>
                        <li><?= $L('update.before_time') ?></li>
                    </ul>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
                    <form method="post" action="/update/run" data-confirm="<?= $L('update.confirm') ?>" id="update-run-form">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <button type="submit" class="btn btn-primary" id="update-run-btn"><?= $L('update.run') ?> v<?= View::e($u['latest']['version']) ?></button>
                    </form>
                    <button type="button" class="btn js-update-check"><?= $L('update.recheck') ?></button>
                </div>
            </div>
        <?php else: ?>
            <div class="card" style="margin-top:18px">
                <div class="empty">
                    <?= $L('update.uptodate') ?><br>
                    <span class="muted"><?= $L('update.uptodate_hint') ?></span>
                </div>
                <div style="display:flex;justify-content:center;margin-top:10px">
                    <button type="button" class="btn js-update-check"><?= $L('update.recheck') ?></button>
                </div>
            </div>
        <?php endif; ?>

        <p class="hint" style="margin-top:14px">
            <?= $L('update.repo') ?>
            <a href="https://github.com/<?= View::e(\App\Core\Version::REPO) ?>" target="_blank" rel="noopener"><?= View::e(\App\Core\Version::REPO) ?> ↗</a>
        </p>
    </div>
    <?php endif; ?>

    <!-- Progress HUD overlay (shown while a step-based update runs). -->
    <div class="update-hud" id="update-hud" aria-hidden="true">
        <div class="update-hud-box">
            <div class="update-hud-title" id="update-hud-title"><?= $L('update.hud_title') ?></div>
            <div class="update-hud-percent" id="update-hud-percent">0%</div>
            <div class="update-hud-track"><div class="update-hud-bar" id="update-hud-bar"></div></div>
            <div class="update-hud-step" id="update-hud-step"><?= $L('update.step_prepare') ?></div>
            <div class="update-hud-log" id="update-hud-log"></div>
            <div class="update-hud-hint" id="update-hud-hint"><?= $L('update.hud_hint') ?></div>
        </div>
    </div>
    <script>
        window.UPDATE_I18N = {
            hud_done:   <?= json_encode(Lang::t('update.hud_done')) ?>,
            hud_failed: <?= json_encode(Lang::t('update.hud_failed')) ?>,
            steps: {
                'update.step_prepare':     <?= json_encode(Lang::t('update.step_prepare')) ?>,
                'update.step_download':    <?= json_encode(Lang::t('update.step_download')) ?>,
                'update.step_extract':     <?= json_encode(Lang::t('update.step_extract')) ?>,
                'update.step_backup_db':   <?= json_encode(Lang::t('update.step_backup_db')) ?>,
                'update.step_backup_code': <?= json_encode(Lang::t('update.step_backup_code')) ?>,
                'update.step_apply':       <?= json_encode(Lang::t('update.step_apply')) ?>,
                'update.step_migrate':     <?= json_encode(Lang::t('update.step_migrate')) ?>,
                'update.step_finalize':    <?= json_encode(Lang::t('update.step_finalize')) ?>
            }
        };
    </script>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var T = window.CS2Theme;
    if (!T) { console.warn('CS2Theme not ready'); return; }

    // Pending selection — themes are only PREVIEWED here and committed on Save,
    // so choosing a preset no longer persists until the user clicks Save.
    var pendingTheme = T.getTheme();
    var pendingAccent = T.getAccent();

    function markActive() {
        document.querySelectorAll('[data-theme-set]').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-theme-set') === pendingTheme);
        });
        var card = document.getElementById('accent-card');
        if (card) card.style.display = (pendingTheme === 'default') ? '' : 'none';
    }

    // preset / theme selection (keeps the current light/dark mode) — PREVIEW only.
    document.querySelectorAll('[data-theme-set]').forEach(function (b) {
        b.addEventListener('click', function () {
            pendingTheme = b.getAttribute('data-theme-set');
            T.previewTheme(pendingTheme);
            markActive();
        });
    });

    // Reflect the current accent on the Default theme's preview card.
    function updateDefaultPreview(hex) {
        var card = document.querySelector('[data-theme-set="default"]');
        if (card) card.style.setProperty('--tp-accent', hex);
    }

    // accent picker (Default theme only)
    var sync = document.querySelector('[data-accent-sync]');
    if (sync) {
        var picker = sync.querySelector('input[type=color]');
        var hidden = sync.querySelector('input[type=hidden]');
        // initialise from saved accent (localStorage wins over server default)
        var savedAccent = T.getAccent();
        if (savedAccent) { picker.value = savedAccent; hidden.value = savedAccent; }
        updateDefaultPreview(picker.value);
        picker.addEventListener('input', function () {
            hidden.value = picker.value;
            pendingAccent = picker.value;
            T.preview(pendingTheme, picker.value);   // preview only; committed on Save
            updateDefaultPreview(picker.value);
        });
        // Reset to the default accent color.
        var resetBtn = document.getElementById('accent-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var def = '#e01e37';
                picker.value = def; hidden.value = def;
                pendingAccent = def;
                T.preview(pendingTheme, def);
                updateDefaultPreview(def);
            });
        }
    }

    // Build a split preview card for a custom theme (accent-based, like presets).
    function previewCard(id, name, accent, deletable) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'theme-card'; b.setAttribute('data-theme-set', id);
        b.style.cssText = '--tp-accent:' + accent + ';';
        var overlay = deletable
            ? '<span class="theme-actions">' +
                  '<span class="theme-act edit" data-act="edit" title="Edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg></span>' +
                  '<span class="theme-act del" data-act="del" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" width="15" height="15"><path d="M6 6l12 12M18 6L6 18"/></svg></span>' +
              '</span>'
            : '';
        b.innerHTML =
            '<span class="theme-check">&check;</span>' +
            overlay +
            '<span class="theme-preview split">' +
                '<span class="tp-half tp-dark"><span class="tp-logo"></span><span class="tp-line on"></span><span class="tp-line"></span></span>' +
                '<span class="tp-half tp-light"><span class="tp-logo"></span><span class="tp-line on"></span><span class="tp-line"></span></span>' +
            '</span>' +
            '<span class="theme-meta"><span class="theme-name">' + name + '</span><span class="theme-tag">Custom</span></span>';
        return b;
    }

    // Load a saved custom theme into the builder for editing.
    function loadIntoBuilder(name) {
        var t = T.customThemes()[name]; if (!t) return;
        var d = t.dark || {}, l = t.light || {}, sh = t.shared || {};
        function set(id, val) { var el = document.getElementById(id); if (el && val) el.value = val; }
        document.getElementById('ct-name').value = name;
        set('ct-accent', sh.accent);
        set('ctd-bg', d.bg); set('ctd-card', d.card); set('ctd-border', d.border); set('ctd-text', d.text); set('ctd-mut', d['text-mut']);
        set('ctl-bg', l.bg); set('ctl-card', l.card); set('ctl-border', l.border); set('ctl-text', l.text); set('ctl-mut', l['text-mut']);
        document.getElementById('ct-name').scrollIntoView({ behavior: 'smooth', block: 'center' });
        document.getElementById('ct-name').focus();
    }

    function renderCustom() {
        var grid = document.getElementById('custom-theme-grid');
        var all = T.customThemes();
        grid.innerHTML = '';
        var keys = Object.keys(all).sort(function (a, b) {
            return a.localeCompare(b, undefined, { sensitivity: 'base' });
        });
        if (!keys.length) { grid.innerHTML = '<span class="muted" style="white-space:nowrap">No custom themes yet &mdash; create one below.</span>'; return; }
        keys.forEach(function (name) {
            var accent = (all[name].shared && all[name].shared.accent) || '#3fb6ff';
            var card = previewCard('custom:' + name, name, accent, true);
            card.addEventListener('click', function (e) {
                var act = e.target.closest('[data-act]');
                if (act) {
                    e.stopPropagation();
                    if (act.getAttribute('data-act') === 'del') {
                        // If the deleted theme is the pending/active one, fall back to Default.
                        if (pendingTheme === 'custom:' + name) { pendingTheme = 'default'; T.previewTheme('default'); }
                        else if (T.getTheme() === 'custom:' + name) { T.setTheme('default'); }
                        T.removeCustom(name);
                        renderCustom(); markActive();
                    } else {
                        loadIntoBuilder(name);
                    }
                    return;
                }
                pendingTheme = 'custom:' + name;
                T.previewTheme(pendingTheme); markActive();
            });
            grid.appendChild(card);
        });
    }

    function v(id) { return document.getElementById(id).value; }

    var saveBtn = document.getElementById('ct-save');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var name = (document.getElementById('ct-name').value || '').trim();
            if (!name) { document.getElementById('ct-name').focus(); return; }
            var accent = v('ct-accent');

            // Full palette per mode. bg-2/bg-3 derive from surface for a cohesive look.
            var dark = {
                'bg': v('ctd-bg'), 'bg-2': v('ctd-card'), 'bg-3': v('ctd-card'), 'card': v('ctd-card'),
                'border': v('ctd-border'), 'text': v('ctd-text'), 'text-dim': v('ctd-mut'), 'text-mut': v('ctd-mut'),
                'shadow': '0 10px 30px rgba(0,0,0,.4)'
            };
            var light = {
                'bg': v('ctl-bg'), 'bg-2': v('ctl-card'), 'bg-3': v('ctl-card'), 'card': v('ctl-card'),
                'border': v('ctl-border'), 'text': v('ctl-text'), 'text-dim': v('ctl-mut'), 'text-mut': v('ctl-mut'),
                'shadow': '0 8px 24px rgba(30,35,60,.1)'
            };
            var shared = {
                'accent': accent, 'accent-2': accent, 'enter': 'animRise', 'enter-dur': '.5s',
                'scroll': 'color-mix(in srgb, ' + accent + ' 40%, transparent)',
                'scroll-hover': 'color-mix(in srgb, ' + accent + ' 60%, transparent)'
            };
            T.saveCustom(name, { dark: dark, light: light, shared: shared });
            // Select the new theme as the PENDING one (committed on Save).
            pendingTheme = 'custom:' + name;
            T.previewTheme(pendingTheme);
            document.getElementById('ct-name').value = '';
            renderCustom(); markActive();
        });
    }

    renderCustom();
    markActive();

    // Mirror the webhook value into the test form so the test can run even
    // before saving (useful in dev / first-time setup).
    var testForm = document.getElementById('discord-test-form');
    if (testForm) {
        testForm.addEventListener('submit', function () {
            var main = document.querySelector('[name="discord_webhook"]:not([type=hidden])');
            var hidden = document.getElementById('discord-test-webhook');
            if (main && hidden) hidden.value = main.value;
        });
    }

    // Remember which tab is active when saving, so the page returns to it.
    var settingsForm = document.getElementById('settings-form');
    if (settingsForm) {
        settingsForm.addEventListener('submit', function () {
            // Wrapped in try/catch so a theme-helper hiccup can NEVER block the
            // actual save: whatever happens here, the native POST still goes out.
            try {
                var activeTab = document.querySelector('.tabs [data-tab].active');
                var field = document.getElementById('settings-active-tab');
                if (activeTab && field) field.value = activeTab.getAttribute('data-tab');
                if (typeof T !== 'undefined' && T) {
                    // Commit the previewed theme/accent (updates the live view).
                    if (typeof T.commit === 'function') T.commit(pendingTheme, pendingAccent);
                    var nameEl = document.getElementById('settings-theme-name');
                    var modeEl = document.getElementById('settings-theme-mode');
                    var customEl = document.getElementById('settings-custom-themes');
                    if (nameEl && typeof T.getTheme === 'function') nameEl.value = T.getTheme();
                    if (modeEl && typeof T.getMode === 'function') modeEl.value = T.getMode();
                    if (customEl && typeof T.customThemes === 'function') {
                        try { customEl.value = JSON.stringify(T.customThemes() || {}); } catch (e) { customEl.value = '{}'; }
                    }
                }
            } catch (e) {
                if (window.console) console.error('[settings submit prep]', e);
                // Do not block submission.
            }
        });
    }

    // Updates tab: step-based update with a progress HUD. Intercept the form
    // submit and drive /update/step sequentially so no single request can hit
    // the host time limit (the blank-500 cause), while showing a real percent.
    var updRunForm = document.getElementById('update-run-form');
    var updRunBtn = document.getElementById('update-run-btn');
    if (updRunForm && updRunBtn) {
        updRunForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (updRunBtn.disabled) return;
            runStepUpdate();
        });
    }

    function runStepUpdate() {
        var I18N = window.UPDATE_I18N || { steps: {}, hud_done: 'Done', hud_failed: 'Update failed' };
        var hud     = document.getElementById('update-hud');
        // Move the HUD to <body> so its position:fixed centres against the
        // viewport, not against any transformed/animated ancestor (the Updates
        // tab panel), which would otherwise offset it.
        if (hud && hud.parentNode !== document.body) { document.body.appendChild(hud); }
        var barEl    = document.getElementById('update-hud-bar');
        var pctEl    = document.getElementById('update-hud-percent');
        var stepEl   = document.getElementById('update-hud-step');
        var logEl    = document.getElementById('update-hud-log');
        var base = (window.CS2_BASE || '');

        if (updRunBtn) { updRunBtn.disabled = true; }
        if (hud) { hud.classList.add('open'); hud.setAttribute('aria-hidden', 'false'); }

        function setProgress(pct, labelKey) {
            if (barEl) barEl.style.width = pct + '%';
            if (pctEl) pctEl.textContent = pct + '%';
            if (stepEl && labelKey && I18N.steps[labelKey]) stepEl.textContent = I18N.steps[labelKey];
        }
        function addLog(lines) {
            if (!logEl || !lines) return;
            lines.forEach(function (ln) {
                var d = document.createElement('div');
                d.textContent = ln;
                logEl.appendChild(d);
            });
            logEl.scrollTop = logEl.scrollHeight;
        }
        function fail(msg) {
            if (stepEl) { stepEl.textContent = I18N.hud_failed + (msg ? ': ' + msg : ''); stepEl.classList.add('is-error'); }
            if (updRunBtn) updRunBtn.disabled = false;
        }

        // A step may outlive a reverse proxy's timeout; the proxy then answers
        // with its own HTML error page while PHP keeps working. Treat 5xx/network
        // errors as "still running" and poll the same step for a while.
        var retryUntil = 0;
        function runStep(id) {
            var body = new URLSearchParams();
            body.set('step', id);
            if (window.CS2_CSRF) body.set('csrf', window.CS2_CSRF);
            fetch(base + '/update/step', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (r) {
                return r.text().then(function (t) {
                    try { return JSON.parse(t); } catch (e) {
                        var title = (t.match(/<title>([^<]*)<\/title>/i) || [])[1] || '';
                        var err = new Error('HTTP ' + r.status + (title ? ' - ' + title.trim() : ''));
                        err.retry = r.status >= 500;
                        throw err;
                    }
                });
            }).then(function (d) {
                retryUntil = 0;
                if (!d || !d.ok) { fail(d && d.message ? d.message : ''); addLog(d && d.log ? d.log : []); return; }
                setProgress(d.percent, d.label);
                addLog(d.log || []);
                if (d.pending) {
                    if (stepEl && d.detail) stepEl.textContent = (I18N.steps[d.label] || '') + ' (' + d.detail + ')';
                    setTimeout(function () { runStep(d.next || id); }, 2500);
                    return;
                }
                if (d.done || !d.next) {
                    setProgress(100, null);
                    if (stepEl) stepEl.textContent = I18N.hud_done;
                    setTimeout(function () { window.location.href = base + '/settings#updates'; window.location.reload(); }, 1500);
                    return;
                }
                runStep(d.next);
            }).catch(function (err) {
                var retry = !err || err.retry !== false;
                if (retry) {
                    if (!retryUntil) retryUntil = Date.now() + 10 * 60 * 1000;
                    if (Date.now() < retryUntil) { setTimeout(function () { runStep(id); }, 4000); return; }
                }
                fail(String(err && err.message ? err.message : err));
            });
        }
        runStep('prepare');
    }

    // The main "Save" belongs to the settings form only. Hide it on the Updates
    // tab (which has its own Check/Update buttons and nothing to save).
    var saveRow = document.getElementById('settings-save-row');
    function syncSaveVisibility() {
        if (!saveRow) return;
        var active = document.querySelector('.tabs [data-tab].active');
        var name = active ? active.getAttribute('data-tab') : '';
        saveRow.style.display = (name === 'updates' || name === 'benefits') ? 'none' : '';
    }
    document.querySelectorAll('.tabs [data-tab]').forEach(function (b) {
        b.addEventListener('click', function () { setTimeout(syncSaveVisibility, 0); });
    });
    // Also handle the initial tab (restored from the URL hash by app.js).
    setTimeout(syncSaveVisibility, 0);
    window.addEventListener('hashchange', function () { setTimeout(syncSaveVisibility, 0); });

    // ---- "Check" for updates: AJAX + centered pop-up with the result ----
    var UP_MSG = {
        checking:  <?= json_encode(Lang::t('update.checking')) ?>,
        found:     <?= json_encode(Lang::t('update.found')) ?>,
        none:      <?= json_encode(Lang::t('update.none_found')) ?>,
        offline:   <?= json_encode(Lang::t('update.check_failed')) ?>,
        gotoGit:   <?= json_encode(Lang::t('update.goto_git')) ?>,
        title:     <?= json_encode(Lang::t('nav.update')) ?>
    };
    function updatePopup(html, kind) {
        // Remove any existing popup first.
        var old = document.getElementById('update-popup-overlay');
        if (old) old.remove();
        var overlay = document.createElement('div');
        overlay.id = 'update-popup-overlay';
        overlay.className = 'popup-overlay';
        overlay.innerHTML =
            '<div class="popup popup-' + (kind || 'info') + '" role="dialog" aria-modal="true">' +
                '<button type="button" class="popup-close" aria-label="Close">&times;</button>' +
                '<div class="popup-title">' + UP_MSG.title + '</div>' +
                '<div class="popup-body">' + html + '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        // Trigger the enter animation.
        requestAnimationFrame(function () { overlay.classList.add('show'); });

        var timer = null;
        function close() {
            if (timer) { clearTimeout(timer); timer = null; }
            overlay.classList.remove('show');
            setTimeout(function () { if (overlay.parentNode) overlay.remove(); }, 250);
        }
        // Close on X, or clicking outside the popup box.
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target.closest('.popup-close')) close();
        });
        // Auto-close after 10 seconds.
        timer = setTimeout(close, 10000);
        return close;
    }

    function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }

    // Update the on-page Updates card to match a fresh check result, live.
    // The hero (current -> latest) is patched in the DOM immediately. The full
    // "Update to vX" card (with release notes + the update button) is PHP-rendered,
    // so if the new state needs it and it isn't on the page yet, reload once so it
    // appears — the cache is already fresh from this check.
    function reflectUpdateState(d) {
        var hero = document.getElementById('update-hero');
        if (!hero) return;
        var next = document.getElementById('update-next');
        var latestVer = document.getElementById('update-latest-ver');
        var badge = document.getElementById('update-badge');
        var upIcon  = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>';
        var okIcon  = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
        // Does the page already show the full "Update to vX" action card?
        // (#update-run-form is the server-rendered "Update to vX" form.)
        var hasFullCard = !!document.getElementById('update-run-form');

        if (d.hasUpdate) {
            hero.classList.add('has-update'); hero.classList.remove('up-to-date');
            if (badge) badge.innerHTML = upIcon;
            if (latestVer) latestVer.textContent = 'v' + d.version;
            if (next) next.style.display = 'flex';
            // Keep the "Update to vX" button in sync with the freshly found
            // version (otherwise it keeps showing the previously rendered one).
            var runBtn = document.getElementById('update-run-btn');
            if (runBtn) runBtn.textContent = <?= json_encode(Lang::t('update.run')) ?> + ' v' + d.version;
            // The action card (release notes + Update button) is server-rendered.
            // If it's not present yet, reload once to bring it in.
            if (!hasFullCard) { location.reload(); }
        } else {
            hero.classList.remove('has-update'); hero.classList.add('up-to-date');
            if (badge) badge.innerHTML = okIcon;
            if (next) next.style.display = 'none';
            // If a stale "Update" card is showing but we're actually up to date, reload to drop it.
            if (hasFullCard) { location.reload(); }
        }
    }

    document.querySelectorAll('.js-update-check').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var orig = btn.textContent;
            btn.disabled = true; btn.textContent = UP_MSG.checking;
            var body = new URLSearchParams();
            if (window.CS2_CSRF) body.set('csrf', window.CS2_CSRF);
            fetch((window.CS2_BASE || '') + '/update/check', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.reachable) { updatePopup(esc(UP_MSG.offline), 'error'); return; }
                if (d.hasUpdate) {
                    var msg = UP_MSG.found.replace('{v}', esc('v' + d.version));
                    if (d.url) {
                        msg += '<div style="margin-top:14px"><a class="btn btn-sm btn-primary" href="' + esc(d.url) + '" target="_blank" rel="noopener">' + esc(UP_MSG.gotoGit) + '</a></div>';
                    }
                    updatePopup(msg, 'update');
                } else {
                    updatePopup(UP_MSG.none.replace('{v}', esc('v' + d.current)), 'ok');
                }
                // Live-refresh the on-page card so it matches the check result
                // WITHOUT needing a manual page refresh.
                reflectUpdateState(d);
            }).catch(function () {
                updatePopup(esc(UP_MSG.offline), 'error');
            }).finally(function () {
                btn.disabled = false; btn.textContent = orig;
            });
        });
    });
});
</script>
