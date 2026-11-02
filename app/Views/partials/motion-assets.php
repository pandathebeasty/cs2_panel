<?php
use App\Core\Version;
use App\Core\View;

$effectsPath = PUBLIC_PATH . '/assets/js/effects.js';
$effectsVersion = Version::current() . (is_file($effectsPath) ? '.' . filemtime($effectsPath) : '');
?>
<script src="/assets/js/effects.js?v=<?= View::e($effectsVersion) ?>"></script>
<?php
foreach (['lenis.css', 'gsap.min.js', 'lenis.min.js'] as $motionAsset) {
    $motionAssetPath = PUBLIC_PATH . '/assets/vendor/' . $motionAsset;
    $motionAssetVersion = Version::current() . (is_file($motionAssetPath) ? '.' . filemtime($motionAssetPath) : '');
    $motionAssetUrl = '/assets/vendor/' . $motionAsset . '?v=' . rawurlencode($motionAssetVersion);
    if (str_ends_with($motionAsset, '.css')) {
        ?>
        <link rel="stylesheet" href="<?= View::e($motionAssetUrl) ?>">
        <?php
    } else {
        ?>
        <script defer src="<?= View::e($motionAssetUrl) ?>"></script>
        <?php
    }
}
?>
