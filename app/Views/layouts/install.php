<?php
use App\Core\Lang;
use App\Core\Settings;
use App\Core\View;

$accent  = Settings::get('theme_accent', '#e01e37');
$favicon = '/assets/img/favicon.svg';
$designFile = PUBLIC_PATH . '/assets/css/design.css';
$designVer = \App\Core\Version::current() . (is_file($designFile) ? '.' . filemtime($designFile) : '');
$portalFile = PUBLIC_PATH . '/assets/js/portal.js';
$portalVer = \App\Core\Version::current() . (is_file($portalFile) ? '.' . filemtime($portalFile) : '');

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html class="install-html" lang="<?= View::e(Lang::current()) ?>" data-theme="default" data-mode="dark" data-mode-pref="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title ?? 'Install') ?></title>
    <link rel="icon" href="<?= View::e($favicon) ?>">
    <link rel="stylesheet" href="/assets/css/app.css">
    <?php foreach (\App\Core\Themes::cssLinks() as $themeCss): ?>
    <link rel="stylesheet" href="<?= View::e($themeCss) ?>">
    <?php endforeach; ?>
    <?php require BASE_PATH . '/app/Views/partials/motion-assets.php'; ?>
    <style>[data-theme="default"] { --accent: <?= View::e($accent) ?>; --accent-2: <?= View::e($accent) ?>; }</style>
    <link rel="stylesheet" href="/assets/css/design.css?v=<?= View::e($designVer) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
    (function () {
        var d = document.documentElement;
        var theme = localStorage.getItem('theme') || 'default';
        var mode = localStorage.getItem('mode') === 'light' ? 'light' : 'dark';
        if (localStorage.getItem('mode') === 'system') localStorage.setItem('mode', 'dark');
        var custom = {};
        try {
            custom = JSON.parse(localStorage.getItem('customThemes') || '{}');
        } catch (error) {
            console.error('[CS2 Panel] Stored installation themes could not be read:', error);
        }
        if (!custom || typeof custom !== 'object' || Array.isArray(custom)) {
            console.error('[CS2 Panel] Stored installation themes must be an object.');
            custom = {};
        }
        var t = theme.indexOf('custom:') === 0 ? custom[theme.slice(7)] : null;
        if (t) {
            function block(selector, values) {
                var css = selector + '{';
                for (var key in values) css += '--' + key + ':' + values[key] + ';';
                return css + '}';
            }
            var style = document.createElement('style');
            style.id = 'custom-theme-style';
            style.textContent = (t.dark ? block('[data-theme="__custom"][data-mode="dark"]', t.dark) : '')
                + (t.light ? block('[data-theme="__custom"][data-mode="light"]', t.light) : '')
                + (t.shared ? block('[data-theme="__custom"]', t.shared) : '');
            document.head.appendChild(style);
        } else if (theme.indexOf('custom:') === 0) {
            console.error('[CS2 Panel] Stored installation theme is unavailable; using the default theme.');
            theme = 'default';
        }
        var accent = localStorage.getItem('accent') || '';
        window.CS2_THEME = { name: theme, mode: mode, accent: accent, custom: custom };
        d.setAttribute('data-mode', mode);
        d.setAttribute('data-mode-pref', mode);
        d.setAttribute('data-theme-name', theme);
        d.setAttribute('data-theme', t ? '__custom' : theme);
        if (theme === 'default' && accent) {
            d.style.setProperty('--accent', accent);
            d.style.setProperty('--accent-2', accent);
        }
    })();
    </script>
</head>
<body class="install-body">
    <?php if ($flashes): ?>
        <div class="install-flash">
            <?php foreach ($flashes as $f): ?>
                <div class="flash flash-<?= View::e($f['type']) ?>"><?= View::e($f['message']) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?= $content ?>
    <script src="/assets/js/app.js"></script>
    <script defer src="/assets/js/portal.js?v=<?= View::e($portalVer) ?>"></script>
</body>
</html>
