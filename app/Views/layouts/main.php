<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Settings;
use App\Core\View;

$siteName = Settings::get('site_name', 'CS2 Panel');
$accent   = Settings::get('theme_accent', '#e01e37');
$logo     = Settings::get('logo_path', '') ?: '/assets/img/logo.svg';
$favicon  = Settings::get('favicon_path', '') ?: '/assets/img/favicon.svg';

// Theme is stored SERVER-SIDE (shared by everyone, every device) — not in the
// browser. Root selects it in Settings and it applies globally.
$themeName   = Settings::get('theme_name', 'default');
$themeMode   = \App\Core\Themes::mode();
$customThemes = Settings::get('custom_themes', '');              // JSON blob
$isCustom    = str_starts_with($themeName, 'custom:');

// Is a newer panel version available? Root-only, and uses the cached check so it
// never hits GitHub on a normal page load (at most once every few hours).
$updateAvailable = false;
if (Auth::isRoot()) {
    try { $updateAvailable = \App\Core\Updater::cachedHasUpdate(); } catch (\Throwable $e) { $updateAvailable = false; }
}
$user     = Auth::user();
$path     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
                                                                               
// Remove the deployment base before matching active navigation.
$base = defined('BASE_URL') ? (string) BASE_URL : '';
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
}
$lang     = Lang::current();

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

// Community links (shown in the top bar).
$discordInvite = Settings::get('discord_invite', '');
$discordUrl = $discordInvite !== ''
    ? (str_starts_with($discordInvite, 'http') ? $discordInvite : 'https://discord.gg/' . ltrim($discordInvite, '/'))
    : '';
$forumUrl = Settings::get('forum_url', '');

$is = fn(string $prefix): string => str_starts_with($path, $prefix) ? 'active' : '';

$icon = function (string $name): string {
    $icons = [
        'dash'    => '<path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>',
        'ban'     => '<circle cx="12" cy="12" r="9"/><path d="M5 5l14 14"/>',
        'mute'    => '<path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M23 9l-6 6M17 9l6 6"/>',
        'warn'    => '<path d="M12 2L2 20h20L12 2z"/><path d="M12 9v5M12 17h.01"/>',
        'admin'   => '<path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z"/>',
        'group'   => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.9"/>',
        'server'  => '<rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="14" width="18" height="6" rx="1"/><path d="M7 7h.01M7 17h.01"/>',
        'vip'     => '<path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/>',
        'benefits'=> '<circle cx="12" cy="8" r="5"/><path d="M8.5 12.5L7 21l5-3 5 3-1.5-8.5"/>',
        'skins'   => '<path d="M14.5 2l-11 11 7.5 7.5 11-11V2z"/><circle cx="17.5" cy="6.5" r="1.5"/>',
        'rank'    => '<path d="M4 20h16M7 20V10M12 20V4M17 20v-7"/>',
        'contest' => '<path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0V4zM7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"/>',
        'store'   => '<path d="M3 9l1-5h16l1 5M4 9v10a1 1 0 001 1h14a1 1 0 001-1V9M3 9h18M9 13h6"/>',
        'credits' => '<ellipse cx="9" cy="6" rx="6" ry="3"/><path d="M3 6v6c0 1.7 2.7 3 6 3s6-1.3 6-3V6"/><ellipse cx="15" cy="15" rx="6" ry="3"/><path d="M9 15v4c0 1.7 2.7 3 6 3s6-1.3 6-3v-4"/>',
        'orders'  => '<path d="M5 7h14l1 14H4L5 7z"/><path d="M9 7V5a3 3 0 016 0v2"/>',
        'stats'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'settings'=> '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
        'users'   => '<rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="8" cy="10" r="2"/><path d="M5 16a3 3 0 016 0M15 9h4M15 13h4M15 17h2"/>',
        'log'     => '<circle cx="4" cy="5" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="19" r="1"/><path d="M9 5h12M9 12h12M9 19h12"/>',
        'chat'    => '<path d="M3 4h13v10H8l-5 4V4z"/><path d="M16 8h5v12l-4-3h-6v-3M6 8h7M6 11h4"/>',
        'appeal'  => '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
        'report'  => '<path d="M4 22V4a1 1 0 011-1h10l-1.5 4L20 7v9H5"/><path d="M4 22h.01"/>',
        'update'  => '<path d="M21 12a9 9 0 11-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'logout'  => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'menu'    => '<path d="M3 12h18M3 6h18M3 18h18"/>',
        'sun'     => '<circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/>',
        'moon'    => '<path d="M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z"/>',
        'globe'   => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 010 20 15 15 0 010-20z"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($icons[$name] ?? '') . '</svg>';
};
$t = fn(string $k) => View::e(Lang::t($k));
$appealsOn = Settings::bool('appeals_enabled', true);
$reportsOn = Settings::bool('reports_enabled', true);
$openAppeals = ($appealsOn && Auth::canModerate()) ? \App\Models\AppealModel::openCount() : 0;
$openReports = ($reportsOn && Auth::canModerate()) ? \App\Models\ReportModel::openCount() : 0;
$statsOn = \App\Models\StatsBackend::enabled();
$statsBase = \App\Models\StatsBackend::isK4System() ? '/k4' : '/zenith';
$navigationSections = [
    'moderation' => ['label' => 'nav.moderation', 'items' => [
        ['path' => '/bans', 'icon' => 'ban', 'label' => 'nav.bans'],
        ['path' => '/mutes', 'icon' => 'mute', 'label' => 'nav.mutes'],
        ['path' => '/warns', 'icon' => 'warn', 'label' => 'nav.warns'],
    ]],
    'server' => ['label' => 'nav.server', 'items' => [
        ['path' => '/servers', 'icon' => 'server', 'label' => 'nav.servers'],
    ]],
];
if ($appealsOn) {
    $navigationSections['moderation']['items'][] = ['path' => '/appeals', 'icon' => 'appeal', 'label' => 'nav.appeals', 'badge' => $openAppeals];
}
if ($reportsOn) {
    $navigationSections['moderation']['items'][] = ['path' => '/reports', 'icon' => 'report', 'label' => 'nav.reports', 'badge' => $openReports];
}
foreach ([
    ['enabled' => Settings::bool('vip_enabled', true) && Auth::canManage(), 'path' => '/vip', 'icon' => 'vip', 'label' => 'nav.vip'],
    ['enabled' => Settings::bool('contests_enabled', true), 'path' => '/contests', 'icon' => 'contest', 'label' => 'nav.contests'],
    ['enabled' => Settings::bool('skins_enabled', false) && Auth::hasSteam(), 'path' => '/skins', 'icon' => 'skins', 'label' => 'nav.skins'],
    ['enabled' => Settings::bool('store_enabled', false), 'path' => '/store', 'icon' => 'store', 'label' => 'nav.store'],
    ['enabled' => Settings::bool('benefits_enabled', false), 'path' => '/benefits', 'icon' => 'benefits', 'label' => 'nav.benefits'],
] as $entry) {
    if ($entry['enabled']) {
        $navigationSections['server']['items'][] = $entry;
    }
}
if ($statsOn) {
    $navigationSections['stats'] = ['label' => 'nav.stats', 'items' => [
        ['path' => $statsBase . '/ranks', 'icon' => 'rank', 'label' => 'nav.ranks'],
        ['path' => $statsBase . '/playtime', 'icon' => 'stats', 'label' => 'nav.playtime'],
    ]];
}
// Store credits (cs2-store) sit under Statistics. They can be enabled
// independently of the ranks/playtime backend, so create the group if needed.
if (\App\Models\StoreCreditsModel::enabled()) {
    if (!isset($navigationSections['stats'])) {
        $navigationSections['stats'] = ['label' => 'nav.stats', 'items' => []];
    }
    $navigationSections['stats']['items'][] = ['path' => '/credits', 'icon' => 'credits', 'label' => 'nav.credits'];
}
if (Auth::canManage()) {
    $navigationSections['administration'] = ['label' => 'nav.administration', 'items' => [
        ['path' => '/admins', 'icon' => 'admin', 'label' => 'nav.admins'],
        ['path' => '/groups', 'icon' => 'group', 'label' => 'nav.groups'],
    ]];
    if (Auth::isRoot()) {
        $navigationSections['administration']['items'][] = ['path' => '/panel-users', 'icon' => 'users', 'label' => 'nav.panel_users'];
    }
    if (Settings::bool('store_enabled', false)) {
        $navigationSections['administration']['items'][] = ['path' => '/orders', 'icon' => 'orders', 'label' => 'nav.orders'];
    }
    $navigationSections['administration']['items'][] = ['path' => '/logs', 'icon' => 'log', 'label' => 'nav.logs'];
    if (\App\Models\ChatLogModel::enabled()) {
        $navigationSections['administration']['items'][] = ['path' => '/chat-logs', 'icon' => 'chat', 'label' => 'nav.chat_logs'];
    }
    $navigationSections['administration']['items'][] = ['path' => '/settings', 'icon' => 'settings', 'label' => 'nav.settings'];
}
?>
<!DOCTYPE html>
<?php
// Only the DEFAULT theme uses the user-chosen accent. For a built-in or custom
// theme the accent comes from the theme's own CSS, so emitting the saved accent
// inline here (which may still be the red default) causes a one-frame red flash
// before the theme stylesheet cascades in. So emit --accent inline ONLY for the
// default theme; otherwise let the theme CSS own it from the first paint.
$inlineVars = "--server-name: '" . str_replace(["'", '"'], '', (string) $siteName) . "'";
if ($themeName === 'default') {
    $inlineVars = '--accent: ' . View::e($accent) . '; --accent-2: ' . View::e($accent) . '; ' . $inlineVars;
}
?>
<html lang="<?= View::e($lang) ?>" style="<?= $inlineVars ?>"
      data-theme="<?= View::e($isCustom ? '__custom' : $themeName) ?>"
      data-theme-name="<?= View::e($themeName) ?>"
      data-mode="<?= View::e($themeMode === 'light' ? 'light' : 'dark') ?>"
      data-mode-pref="<?= View::e($themeMode) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($siteName) ?></title>
    <link rel="icon" href="<?= View::e($favicon) ?>">
    <?php
    // Cache-busting token: the panel version PLUS the CSS/JS file modification
    // time. Using filemtime means any edit to app.css/app.js changes the ?v=
    // value automatically, so browsers never serve a stale stylesheet after an
    // upload (the version string alone doesn't change on a CSS-only fix).
    $assetVer = \App\Core\Version::current();
    $cssFile = PUBLIC_PATH . '/assets/css/app.css';
    $jsFile  = PUBLIC_PATH . '/assets/js/app.js';
    $cssVer  = $assetVer . (is_file($cssFile) ? '.' . filemtime($cssFile) : '');
    $jsVer   = $assetVer . (is_file($jsFile) ? '.' . filemtime($jsFile) : '');
    $designFile = PUBLIC_PATH . '/assets/css/design.css';
    $designVer = $assetVer . (is_file($designFile) ? '.' . filemtime($designFile) : '');
    $portalFile = PUBLIC_PATH . '/assets/js/portal.js';
    $portalVer = $assetVer . (is_file($portalFile) ? '.' . filemtime($portalFile) : '');
    $searchFile = PUBLIC_PATH . '/assets/js/live-search.js';
    $searchVer = $assetVer . (is_file($searchFile) ? '.' . filemtime($searchFile) : '');
    ?>
    <!-- Never cache the HTML document itself, so pages are always fresh. -->
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <script>
        // Clear any Service Worker / Cache Storage on load so the site never runs
        // a stale cached version (esp. on mobile where CSS/JS cache aggressively).
        (function () {
            try {
                if (window.caches && caches.keys) {
                    caches.keys().then(function (keys) { keys.forEach(function (k) { caches.delete(k); }); });
                }
                if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {
                    navigator.serviceWorker.getRegistrations().then(function (rs) { rs.forEach(function (r) { r.unregister(); }); });
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= View::e($cssVer) ?>">
    <?php foreach (\App\Core\Themes::cssLinks() as $themeCss): ?>
    <link rel="stylesheet" href="<?= View::e($themeCss) ?>">
    <?php endforeach; ?>
    <?php require BASE_PATH . '/app/Views/partials/motion-assets.php'; ?>
    <link rel="stylesheet" href="/assets/css/design.css?v=<?= View::e($designVer) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Mono:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800;900&family=Oxanium:wght@400;500;600;700;800&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Theme config comes from the SERVER (shared for everyone). No localStorage. -->
    <script>
        window.CS2_THEME = {
            name: <?= json_encode($themeName) ?>,
            mode: <?= json_encode($themeMode) ?>,
            accent: <?= json_encode($accent) ?>,
            custom: <?= $customThemes !== '' ? $customThemes : '{}' ?>
        };
    </script>
    <?php if ($isCustom): ?>
    <!-- Inject the selected custom theme's CSS (from server JSON) before paint. -->
    <script>
        (function () {
            try {
                var name = window.CS2_THEME.name.slice(7);
                var t = (window.CS2_THEME.custom || {})[name];
                if (t) {
                    function blk(sel, v){ var c=sel+'{'; for(var k in v) c+='--'+k+':'+v[k]+';'; return c+'}'; }
                    var css = (t.dark?blk('[data-theme="__custom"][data-mode="dark"]',t.dark):'')
                            + (t.light?blk('[data-theme="__custom"][data-mode="light"]',t.light):'')
                            + (t.shared?blk('[data-theme="__custom"]',t.shared):'');
                    var s = document.createElement('style'); s.id='custom-theme-style'; s.textContent=css;
                    document.head.appendChild(s);
                }
            } catch (e) {}
        })();
    </script>
    <?php endif; ?>
    <script>
        // Match the saved mode before the first paint.
        (function () {
            var d = document.documentElement;
            var cfg = window.CS2_THEME;
            var mode = cfg.mode === 'light' ? 'light' : 'dark';
            d.setAttribute('data-mode', mode);
            d.setAttribute('data-mode-pref', mode);
            d.setAttribute('data-theme-name', cfg.name);
            d.setAttribute('data-theme', cfg.name.indexOf('custom:') === 0 ? '__custom' : cfg.name);
            if (cfg.name === 'default' && cfg.accent) { d.style.setProperty('--accent', cfg.accent); d.style.setProperty('--accent-2', cfg.accent); }
            // Sidebar collapsed state is a per-device preference (kept in localStorage).
            if (localStorage.getItem('sidebarCollapsed') === '1') d.classList.add('sidebar-collapsed');
        })();
    </script>
</head>
<body class="panel-body<?= $path === '/' ? ' portal-home' : '' ?>">
<script>
    window.CS2_BASE = <?= json_encode(defined('BASE_URL') ? BASE_URL : '') ?>;
    <?php
    // CSRF token for AJAX theme persistence (same token the forms use).
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    ?>
    window.CS2_CSRF = <?= json_encode($_SESSION['csrf']) ?>;
</script>
<div class="app portal-app">
    <aside class="sidebar portal-drawer" id="portal-navigation">
        <div class="brand">
            <img src="<?= View::e($logo) ?>" alt="logo">
            <div class="brand-text">
                <div class="name" title="<?= View::e($siteName) ?>"><?= View::e($siteName) ?></div>
            </div>
            <button class="collapse-toggle" type="button" aria-label="Collapse menu" title="Collapse menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
            </button>
        </div>
        <div class="drawer-caption"><?= $t('portal.directory') ?></div>

        <?php
        // A collapsible section: a clickable label (caret) + a group of items.
        $sectionCaret = '<svg class="nav-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
        ?>
        <a class="nav-item <?= $path === '/' ? 'active' : '' ?>" href="/"><?= $icon('dash') ?> <?= $t('nav.dashboard') ?></a>

        <?php foreach ($navigationSections as $sectionId => $section): ?>
        <div class="nav-section" data-section="<?= View::e($sectionId) ?>">
            <button type="button" class="nav-label"><?= $t($section['label']) ?><?= $sectionCaret ?></button>
            <div class="nav-group">
                <?php foreach ($section['items'] as $entry): ?>
                <a class="nav-item <?= $is($entry['path']) ?>" href="<?= View::e($entry['path']) ?>">
                    <?= $icon($entry['icon']) ?> <?= $t($entry['label']) ?>
                    <?php if (!empty($entry['badge'])): ?><span class="tab-badge"><?= (int) $entry['badge'] ?></span><?php endif; ?>
                    <?php if ($entry['path'] === '/settings' && $updateAvailable): ?><span class="nav-dot" title="<?= $t('update.available') ?>"></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div style="flex:1"></div>
        <a class="nav-item" href="/logout"><?= $icon('logout') ?> <?= $t('nav.logout') ?></a>
    </aside>
    <div class="sidebar-backdrop" aria-hidden="true"></div>

    <div class="main">
        <header class="topbar portal-header">
            <div class="topbar-heading">
                <a class="portal-brand" href="/">
                    <img src="<?= View::e($logo) ?>" alt="">
                    <span><strong><?= View::e($siteName) ?></strong></span>
                </a>
            </div>
            <nav class="portal-primary" aria-label="<?= $t('portal.navigation') ?>">
                <a class="portal-nav-link <?= $path === '/' ? 'active' : '' ?>" href="/"><?= $t('nav.dashboard') ?></a>
                <?php foreach ($navigationSections as $sectionId => $section): ?>
                    <?php $sectionActive = (bool) array_filter($section['items'], fn(array $entry): bool => $is($entry['path']) === 'active'); ?>
                    <div class="panel-nav-section<?= $sectionActive ? ' active' : '' ?>" data-panel-dropdown>
                        <button class="panel-nav-trigger" type="button" aria-expanded="false" aria-controls="panel-menu-<?= View::e($sectionId) ?>">
                            <?= $t($section['label']) ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="panel-nav-panel" id="panel-menu-<?= View::e($sectionId) ?>" hidden>
                            <span class="panel-nav-heading"><?= $t($section['label']) ?></span>
                            <?php foreach ($section['items'] as $entry): ?>
                                <a class="panel-nav-item <?= $is($entry['path']) ?>" href="<?= View::e($entry['path']) ?>">
                                    <?= $icon($entry['icon']) ?><span><?= $t($entry['label']) ?></span>
                                    <?php if (!empty($entry['badge'])): ?><span class="tab-badge"><?= (int) $entry['badge'] ?></span><?php endif; ?>
                                    <?php if ($entry['path'] === '/settings' && $updateAvailable): ?><span class="nav-dot" title="<?= $t('update.available') ?>"></span><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </nav>
            <div class="topbar-actions">
                <button class="btn portal-menu menu-toggle" aria-label="<?= $t('portal.directory') ?>" aria-controls="portal-navigation" aria-expanded="false">
                    <?= $icon('menu') ?>
                </button>
                <!-- Live local date/time (the visitor's own timezone, updated client-side). -->
                <div class="topbar-clock" id="topbar-clock" aria-hidden="true">
                    <span class="clock-time">--:--</span>
                    <span class="clock-date"></span>
                </div>
                <?php if ($discordUrl !== '' || $forumUrl !== ''): ?>
                    <div class="topbar-links">
                        <?php if ($discordUrl !== ''): ?>
                            <a class="topbar-link discord" href="<?= View::e($discordUrl) ?>" target="_blank" rel="noopener" title="Discord">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="17" height="17"><path d="M20 4.4A19 19 0 0015.5 3l-.3.5a14 14 0 014 2 16 16 0 00-13.5 0 14 14 0 014-2L9.5 3A19 19 0 004 4.4 20 20 0 002 18.6 19 19 0 007.7 21l.4-.6a12 12 0 01-2-1l.5-.3a13 13 0 0011 0l.5.3a12 12 0 01-2 1l.4.6A19 19 0 0022 18.6 20 20 0 0020 4.4zM9 15a1.7 1.7 0 010-3.4A1.7 1.7 0 019 15zm6 0a1.7 1.7 0 010-3.4A1.7 1.7 0 0115 15z"/></svg>
                                <span>Discord</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($forumUrl !== ''): ?>
                            <a class="topbar-link" href="<?= View::e($forumUrl) ?>" target="_blank" rel="noopener" title="Forum">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                                <span>Forum</span>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
<?php /* Language is a site-wide setting changed only from Settings > General
          (owner only, applied on Save) — no quick switcher in the topbar. */ ?>

                <!-- Theme mode is shared by the panel and changed only by managers. -->
                <?php require BASE_PATH . '/app/Views/partials/effects-control.php'; ?>
                <?php if (Auth::canManage()): ?>
                <button class="btn btn-icon theme-toggle" aria-label="<?= $t('theme.toggle') ?>" title="<?= $t('theme.toggle') ?>">
                    <span class="theme-ico theme-ico-light"><?= $icon('sun') ?></span>
                    <span class="theme-ico theme-ico-dark"><?= $icon('moon') ?></span>
                </button>
                <?php endif; ?>

                <?php if ($user): ?>
                    <div class="dropdown" id="user-dd">
                        <button class="user-chip dropdown-toggle" type="button">
                            <div style="text-align:right">
                                <div style="font-weight:600;font-size:13px"><?= View::e($user['name']) ?></div>
                                <div class="role"><?= View::e(ucfirst(Auth::role())) ?></div>
                            </div>
                            <img src="<?= View::e($user['avatar']) ?>" alt="avatar">
                        </button>
                        <div class="dropdown-menu">
                            <button type="button" class="dropdown-item" data-modal="modal-profile"><?= $t('nav.profile') ?></button>
                            <a class="dropdown-item" href="/logout"><?= $t('nav.logout') ?></a>
                        </div>
                    </div>
                <?php elseif (Auth::isGuest()): ?>
                    <!-- Guest: show a "Guest" chip with a Sign-in dropdown. -->
                    <div class="dropdown" id="user-dd">
                        <button class="user-chip dropdown-toggle" type="button">
                            <div style="text-align:right">
                                <div style="font-weight:600;font-size:13px"><?= $t('auth.guest') ?></div>
                                <div class="role"><?= $t('auth.guest_tag') ?></div>
                            </div>
                            <span class="user-chip-guest" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span>
                        </button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="/auth/steam"><?= $t('auth.login_steam') ?></a>
                            <a class="dropdown-item" href="/logout"><?= $t('nav.logout') ?></a>
                        </div>
                    </div>
                <?php else: ?>
                    <a class="btn-steam-sm" href="/auth/steam">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.5 2 2 6.5 2 12l5.4 2.2a2.8 2.8 0 011.6-.5l2.4-3.5v-.1a3.7 3.7 0 113.7 3.7h-.1l-3.4 2.5a2.8 2.8 0 01-5.5.7L2 15.4A10 10 0 1012 2z"/></svg>
                        Sign in
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($path !== '/'): ?>
        <section class="portal-page-heading">
            <div>
                <span class="portal-kicker"><?= $t('portal.community') ?> <span aria-hidden="true">/</span> <?= View::e($siteName) ?></span>
                <h1><?= View::e($title ?? $siteName) ?></h1>
            </div>
        </section>
        <?php endif; ?>

        <div class="content page-enter">
            <?php foreach ($flashes as $f): ?>
                <div class="flash flash-<?= View::e($f['type']) ?>"><?= View::e($f['message']) ?></div>
            <?php endforeach; ?>
            <?= $content ?>
        </div>

        <footer class="app-footer">
            <img src="<?= View::e($logo) ?>" alt="" class="footer-logo">
            <?= \App\Core\Brand::footer() ?>
        </footer>
    </div>
</div>

<?php if ($user): ?>
<?php if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); } ?>
<div class="modal-overlay" id="modal-profile">
    <div class="modal profile-modal">
        <div class="modal-head"><h3><?= $t('nav.profile') ?></h3><button type="button" class="modal-close">&times;</button></div>
        <div class="modal-body">
            <div class="profile-head">
                <img class="profile-avatar" src="<?= View::e($user['avatar']) ?>" alt="">
                <div class="profile-id">
                    <div class="profile-name"><?= View::e($user['name']) ?></div>
                    <span class="badge badge-role-admin"><?= View::e(ucfirst(Auth::role())) ?></span>
                </div>
            </div>

            <dl class="profile-grid">
                <dt><?= $t('common.steamid64') ?></dt>
                <dd class="mono"><?= View::e($user['steamid']) ?></dd>
                <dt><?= $t('profile.steam_profile') ?></dt>
                <dd><a href="<?= View::e(App\Core\Steam::profileUrl($user['steamid'])) ?>" target="_blank" rel="noopener"><?= $t('profile.open_steam') ?></a></dd>
                <dt><?= $t('profile.discord') ?></dt>
                <dd id="profile-discord-view" class="muted">—</dd>
            </dl>

            <form id="profile-form" method="post" action="<?= View::e(\App\Core\Url::to('/profile')) ?>">
                <input type="hidden" name="csrf" value="<?= View::e($_SESSION['csrf'] ?? '') ?>">
                <div class="field">
                    <label><?= $t('profile.discord') ?></label>
                    <input type="text" name="discord_tag" id="profile-discord-input" placeholder="username" maxlength="64" autocomplete="off">
                    <p class="hint" style="margin-top:4px"><?= $t('profile.discord_hint') ?></p>
                </div>
                <div class="modal-foot" style="padding-left:0;padding-right:0;border:0">
                    <button type="button" class="btn btn-ghost modal-close"><?= $t('common.cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><?= $t('common.save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var base = window.CS2_BASE || '';
    var view = document.getElementById('profile-discord-view');
    var input = document.getElementById('profile-discord-input');
    var form = document.getElementById('profile-form');
    if (!form) return;

    function render(tag) {
        view.textContent = tag || '—';
        view.classList.toggle('muted', !tag);
        input.value = tag || '';
    }
    document.querySelectorAll('[data-modal="modal-profile"]').forEach(function (b) {
        b.addEventListener('click', function () {
            fetch(base + '/profile/card', { headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.ok) render(d.discord_tag); })
                .catch(function () {});
        });
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form);
        fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) {
                    render(d.discord_tag);
                    document.getElementById('modal-profile').classList.remove('open');
                } else if (d) {
                    window.alert(d.message || 'Error');
                }
            })
            .catch(function () {});
    });
})();
</script>
<?php endif; ?>
<script src="/assets/js/app.js?v=<?= View::e($jsVer) ?>"></script>
<script defer src="/assets/js/live-search.js?v=<?= View::e($searchVer) ?>"></script>
<script defer src="/assets/js/portal.js?v=<?= View::e($portalVer) ?>"></script>
</body>
</html>
