<?php
use App\Core\Lang;
use App\Core\View;

/** @var array<int,array<string,mixed>> $cards */
$title = Lang::t('benefits.title');
$L = fn(string $k) => View::e(Lang::t($k));

/**
 * Resolve an icon value to HTML. The value may be:
 *   - an uploaded file   (ben_<hex>.ext)        -> <img> from the uploads dir
 *   - a bundled icon name (vip-gold, admin, ...) -> <img> from the icons dir
 *   - an explicit image filename (foo.svg/png)   -> <img> from the icons dir
 *   - an emoji / short text                       -> printed as-is
 */
$benefitIcon = static function (string $icon): string {
    $icon = trim($icon);
    if ($icon === '') {
        return '';
    }
    // Our own uploaded files live under the uploads sub-folder.
    if (preg_match('/^ben_[a-f0-9]{12}\.(png|jpe?g|gif|webp|svg)$/i', $icon)) {
        $src = url('/assets/img/benefits/uploads/' . $icon);
        return '<img class="benefits-icon-img" loading="lazy" decoding="async" src="' . View::e($src) . '" alt="">';
    }
    $known = [
        'vip-diamond', 'vip-gold', 'vip-silver', 'vip-bronze', 'admin', 'credits',
        'vip-bronze-premium', 'vip-silver-premium', 'vip-gold-premium', 'vip-diamond-premium', 'vip-supreme-premium',
    ];
    $file = '';
    if (preg_match('/\.(svg|png|webp|jpg|jpeg|gif)$/i', $icon)) {
        $file = $icon;
    } elseif (in_array(strtolower($icon), $known, true)) {
        $file = strtolower($icon) . '.svg';
    }
    if ($file !== '') {
        $src = url('/assets/img/benefits/' . $file);
        return '<img class="benefits-icon-img" loading="lazy" decoding="async" src="' . View::e($src) . '" alt="">';
    }
    return '<span class="benefits-icon-emoji">' . View::e($icon) . '</span>';
};

/**
 * Render the perk list from the free-text description. Each non-empty line
 * becomes a styled row. A line of the form "Label <sep> Value" (sep = ➤ > :
 * – - |) is split into a label (left) and value (right); YES/NO values become
 * coloured pills, numeric/other values get an accent. A line without a
 * separator is shown as a plain centered row.
 */
$benefitRows = static function (string $desc): string {
    $lines = preg_split('/\r\n|\r|\n/', trim($desc)) ?: [];
    $out = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        // Split on the first separator character/group.
        $parts = preg_split('/\s*(?:➤|»|=>|->|[>:|–—-])\s*/u', $line, 2);
        if (is_array($parts) && count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
            $label = trim($parts[0]);
            $value = trim($parts[1]);
            $norm  = strtolower($value);
            if (in_array($norm, ['yes', 'da', 'true', 'on', '✓', 'enabled'], true)) {
                $valHtml = '<span class="bp-yes">✓ ' . View::e($value) . '</span>';
            } elseif (in_array($norm, ['no', 'nu', 'false', 'off', '✗', 'disabled'], true)) {
                $valHtml = '<span class="bp-no">✗ ' . View::e($value) . '</span>';
            } else {
                // Numbers / money / anything else gets the accent value style.
                $cls = preg_match('/\d/', $value) ? 'bp-val bp-num' : 'bp-val';
                $valHtml = '<span class="' . $cls . '">' . View::e($value) . '</span>';
            }
            $out .= '<li class="bp-row"><span class="bp-label">' . View::e($label) . '</span>' . $valHtml . '</li>';
        } else {
            // No separator: a plain line (e.g. a note or a heading).
            $out .= '<li class="bp-row bp-plain">' . View::e($line) . '</li>';
        }
    }
    return $out !== '' ? '<ul class="bp-list">' . $out . '</ul>' : '';
};
?>

<div class="benefits-header">
    <h2><?= $L('benefits.title') ?></h2>
    <p class="muted"><?= $L('benefits.subtitle') ?></p>
</div>

<?php if (!$cards): ?>
    <div class="card benefits-empty">
        <p class="muted"><?= $L('benefits.empty') ?></p>
    </div>
<?php else: ?>
    <div class="benefits-grid">
        <?php foreach ($cards as $item): ?>
            <?php
                $iconHtml = $benefitIcon((string) ($item['icon'] ?? ''));
                $desc = (string) ($item['description'] ?? '');
            ?>
            <section class="benefits-card card">
                <?php if ($iconHtml !== ''): ?>
                    <div class="benefits-card-icon"><?= $iconHtml ?></div>
                <?php endif; ?>
                <h3 class="benefits-card-title"><?= View::e($item['category'] ?? '') ?></h3>
                <?php if (trim($desc) !== ''): ?>
                    <div class="benefits-card-desc"><?= $benefitRows($desc) ?></div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
