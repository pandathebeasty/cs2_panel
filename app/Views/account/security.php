<?php
use App\Core\Lang;
use App\Core\View;
/** @var ?array $row @var ?array $setup @var bool $required @var ?array $newCodes @var int $remaining @var string $csrf */
$title = Lang::t('2fa.security_title');
$L = fn(string $k) => View::e(Lang::t($k));
$csrfField = '<input type="hidden" name="csrf" value="' . View::e($csrf) . '">';
?>
<div class="x-page-head">
    <div>
        <h1 class="x-page-title"><?= $L('2fa.security_title') ?></h1>
        <p class="muted"><?= $L('2fa.security_sub') ?></p>
    </div>
    <span class="badge <?= $row ? 'badge-active' : 'badge-expired' ?>"><?= $row ? $L('2fa.status_on') : $L('2fa.status_off') ?></span>
</div>

<?php if ($required && !$row): ?>
    <div class="x-callout x-callout-warn" role="alert"><?= $L('2fa.required_notice') ?></div>
<?php endif; ?>

<?php if ($newCodes): ?>
    <div class="card twofa-codes">
        <div class="card-title"><?= $L('2fa.codes_title') ?></div>
        <p class="muted"><?= $L('2fa.codes_hint') ?></p>
        <div class="twofa-code-grid mono" id="twofa-codes"><?php foreach ($newCodes as $c): ?><span><?= View::e($c) ?></span> <?php endforeach; ?></div>
        <button type="button" class="btn btn-sm js-copy" data-copy-target="#twofa-codes"><?= $L('2fa.copy_codes') ?></button>
    </div>
<?php endif; ?>

<?php if ($setup): ?>
    <div class="card twofa-setup">
        <div class="card-title"><?= $L('2fa.setup_title') ?></div>
        <ol class="twofa-steps">
            <li><?= $L('2fa.step_app') ?></li>
            <li><?= $L('2fa.step_scan') ?></li>
            <li><?= $L('2fa.step_code') ?></li>
        </ol>
        <div class="twofa-setup-grid">
            <div class="twofa-qr" id="twofa-qr" data-uri="<?= View::e($setup['uri']) ?>" role="img" aria-label="QR code"></div>
            <div class="twofa-setup-side">
                <label class="muted" for="twofa-secret"><?= $L('2fa.manual_key') ?></label>
                <input id="twofa-secret" class="mono twofa-secret" type="text" readonly value="<?= View::e(trim(chunk_split($setup['secret'], 4, ' '))) ?>">
                <form method="post" action="<?= View::e(url('/account/security/enable')) ?>" class="twofa-form" autocomplete="off">
                    <?= $csrfField ?>
                    <input class="twofa-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000" required aria-label="<?= $L('2fa.code') ?>">
                    <button class="btn btn-primary" type="submit"><?= $L('2fa.enable') ?></button>
                </form>
            </div>
        </div>
    </div>
    <script src="<?= View::e(url('/assets/vendor/qrcode.js?v=' . @filemtime(PUBLIC_PATH . '/assets/vendor/qrcode.js'))) ?>"></script>
    <script>
    (function () {
        var box = document.getElementById('twofa-qr');
        if (!box || typeof qrcode !== 'function') return;
        var qr = qrcode(0, 'M');
        qr.addData(box.getAttribute('data-uri'));
        qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 3, scalable: true });
    })();
    </script>
<?php else: ?>
    <div class="grid twofa-manage">
        <div class="card">
            <div class="card-title"><?= $L('2fa.recovery_title') ?></div>
            <p class="muted"><?= View::e(Lang::t('2fa.recovery_left', ['n' => $remaining])) ?></p>
            <form method="post" action="<?= View::e(url('/account/security/recovery')) ?>" class="twofa-form" autocomplete="off">
                <?= $csrfField ?>
                <input class="twofa-code" type="text" name="code" inputmode="numeric" maxlength="7" placeholder="000000" required aria-label="<?= $L('2fa.code') ?>">
                <button class="btn" type="submit"><?= $L('2fa.regenerate') ?></button>
            </form>
        </div>
        <?php if (!$required || \App\Core\Auth::isOwner()): ?>
        <div class="card">
            <div class="card-title"><?= $L('2fa.disable_title') ?></div>
            <p class="muted"><?= $L('2fa.disable_hint') ?></p>
            <form method="post" action="<?= View::e(url('/account/security/disable')) ?>" class="twofa-form" autocomplete="off"
                  data-confirm="<?= $L('2fa.disable_confirm') ?>">
                <?= $csrfField ?>
                <input class="twofa-code" type="text" name="code" maxlength="11" placeholder="000000" required aria-label="<?= $L('2fa.code') ?>">
                <button class="btn btn-danger" type="submit"><?= $L('2fa.disable') ?></button>
            </form>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
