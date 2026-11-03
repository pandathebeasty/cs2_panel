<?php
use App\Core\Settings;
use App\Core\View;

$siteName = Settings::get('site_name', 'CS2 Panel');
$accent   = Settings::get('theme_accent', '#e01e37');
$favicon  = Settings::get('favicon_path', '') ?: '/assets/img/favicon.svg';

// Theme is stored SERVER-SIDE (shared by everyone, every device) — the login
// page must reflect the same theme root selected in Settings, so fresh visitors
// (who have no localStorage yet) still see the correct theme.
$themeName    = Settings::get('theme_name', 'default');
$themeMode    = \App\Core\Themes::mode();
$customThemes = Settings::get('custom_themes', '');              // JSON blob
$isCustom = str_starts_with($themeName, 'custom:');
$accentVars = $themeName === 'default'
    ? '--accent: ' . View::e($accent) . '; --accent-2: ' . View::e($accent) . '; '
    : '';
$assetVer = \App\Core\Version::current();
$cssFile = PUBLIC_PATH . '/assets/css/app.css';
$jsFile  = PUBLIC_PATH . '/assets/js/app.js';
$cssVer  = $assetVer . (is_file($cssFile) ? '.' . filemtime($cssFile) : '');
$jsVer   = $assetVer . (is_file($jsFile) ? '.' . filemtime($jsFile) : '');
$designFile = PUBLIC_PATH . '/assets/css/design.css';
$designVer = $assetVer . (is_file($designFile) ? '.' . filemtime($designFile) : '');
$portalFile = PUBLIC_PATH . '/assets/js/portal.js';
$portalVer = $assetVer . (is_file($portalFile) ? '.' . filemtime($portalFile) : '');

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="<?= View::e(App\Core\Lang::current()) ?>" style="<?= $accentVars ?>--server-name: '<?= View::e(str_replace(["'", '"'], '', (string) $siteName)) ?>';"
      data-theme="<?= View::e($isCustom ? '__custom' : $themeName) ?>"
      data-theme-name="<?= View::e($themeName) ?>"
      data-mode="<?= View::e($themeMode === 'light' ? 'light' : 'dark') ?>"
      data-mode-pref="<?= View::e($themeMode) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title ?? $siteName) ?></title>
    <link rel="icon" href="<?= View::e($favicon) ?>">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= View::e($cssVer) ?>">
    <?php foreach (\App\Core\Themes::cssLinks($themeName) as $themeCss): ?>
    <link rel="stylesheet" href="<?= View::e($themeCss) ?>">
    <?php endforeach; ?>
    <?php require BASE_PATH . '/app/Views/partials/motion-assets.php'; ?>
    <link rel="stylesheet" href="/assets/css/design.css?v=<?= View::e($designVer) ?>">
    <?php $enhanceCss = PUBLIC_PATH . '/assets/css/enhance.css'; ?>
    <link rel="stylesheet" href="/assets/css/enhance.css?v=<?= View::e(\App\Core\Version::current() . (is_file($enhanceCss) ? '.' . filemtime($enhanceCss) : '')) ?>">
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
        })();
    </script>
</head>
<body class="auth-body">
    <div class="effects-tools"><?php require BASE_PATH . '/app/Views/partials/effects-control.php'; ?></div>
    <div class="auth-wrap">
        <?php if ($flashes): ?>
            <div style="position:fixed;top:20px;left:50%;transform:translateX(-50%);max-width:440px;width:90%">
                <?php foreach ($flashes as $f): ?>
                    <div class="flash flash-<?= View::e($f['type']) ?>"><?= View::e($f['message']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?= $content ?>
    </div>
    <script src="/assets/js/app.js?v=<?= View::e($jsVer) ?>"></script>
    <script defer src="/assets/js/portal.js?v=<?= View::e($portalVer) ?>"></script>
</body>
</html>
