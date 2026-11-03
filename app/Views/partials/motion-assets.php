<?php
use App\Core\Version;
use App\Core\View;

$effectsPath = PUBLIC_PATH . '/assets/js/effects.js';
$effectsVersion = Version::current() . (is_file($effectsPath) ? '.' . filemtime($effectsPath) : '');
$motionUrls = [];
foreach (['lenis.css', 'gsap.min.js', 'lenis.min.js'] as $motionAsset) {
    $motionAssetPath = PUBLIC_PATH . '/assets/vendor/' . $motionAsset;
    $motionAssetVersion = Version::current() . (is_file($motionAssetPath) ? '.' . filemtime($motionAssetPath) : '');
    $motionUrls[$motionAsset] = (defined('BASE_URL') ? BASE_URL : '') . '/assets/vendor/' . $motionAsset . '?v=' . rawurlencode($motionAssetVersion);
}
?>
<script src="/assets/js/effects.js?v=<?= View::e($effectsVersion) ?>"></script>
<script>
    // GSAP/Lenis (~150 KB) are only needed when motion effects are on. Written by
    // the parser so they stay ordered "defer" scripts ahead of portal.js.
    (function () {
        var reduced = document.documentElement.getAttribute('data-effects') === 'reduced'
            || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        window.CS2_MOTION_LOADED = !reduced;
        if (reduced) {
            document.addEventListener('cs2:effectschange', function (e) {
                if (e.detail && e.detail.effective !== 'reduced' && !window.gsap) window.location.reload();
            });
            return;
        }
        var u = <?= json_encode($motionUrls, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        document.write('<link rel="stylesheet" href="' + u['lenis.css'] + '">'
            + '<script defer src="' + u['gsap.min.js'] + '"><\/script>'
            + '<script defer src="' + u['lenis.min.js'] + '"><\/script>');
    })();
</script>
<noscript><link rel="stylesheet" href="<?= View::e($motionUrls['lenis.css']) ?>"></noscript>