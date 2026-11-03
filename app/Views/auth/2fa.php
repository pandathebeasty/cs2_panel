<?php
use App\Core\Lang;
use App\Core\View;
/** @var array $pendingUser @var string $csrf */
$title = Lang::t('2fa.title');
$L = fn(string $k) => View::e(Lang::t($k));
?>
<div class="auth-card twofa-card">
    <div class="twofa-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.4"/></svg>
    </div>
    <h2><?= $L('2fa.title') ?></h2>
    <p class="muted"><?= View::e(Lang::t('2fa.prompt', ['name' => (string) ($pendingUser['name'] ?? '')])) ?></p>
    <form method="post" action="<?= View::e(url('/2fa')) ?>" class="twofa-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <input class="twofa-code" type="text" name="code" inputmode="text" autocomplete="one-time-code" maxlength="11"
               placeholder="000000" aria-label="<?= $L('2fa.code') ?>" required autofocus>
        <button class="btn btn-primary" type="submit"><?= $L('2fa.verify') ?></button>
    </form>
    <p class="muted twofa-hint"><?= $L('2fa.recovery_hint') ?></p>
    <form method="post" action="<?= View::e(url('/2fa/cancel')) ?>">
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <button class="btn btn-ghost btn-sm" type="submit"><?= $L('2fa.cancel') ?></button>
    </form>
</div>
