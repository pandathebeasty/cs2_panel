<?php use App\Core\Lang; use App\Core\View; $title = Lang::t('steam.required_title'); ?>
<div class="card"><div class="empty steam-required">
    <svg class="steam-required-ico" width="52" height="52" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.5 2 2 6.5 2 12l5.4 2.2a2.8 2.8 0 011.6-.5l2.4-3.5v-.1a3.7 3.7 0 113.7 3.7h-.1l-3.4 2.5a2.8 2.8 0 01-5.5.7L2 15.4A10 10 0 1012 2zm-3.9 15.2l-1.2-.5a2.1 2.1 0 003.9-.3 2.1 2.1 0 10-2.7-2.7l1.3.5a1.5 1.5 0 11-1.3 2.7zM18 9.5a2.5 2.5 0 10-5 0 2.5 2.5 0 005 0zm-4.2 0a1.7 1.7 0 113.4 0 1.7 1.7 0 01-3.4 0z"/></svg>
    <h3 class="steam-required-title"><?= View::e(Lang::t('steam.required_title')) ?></h3>
    <p class="steam-required-msg"><?= View::e(Lang::t('steam.required_msg')) ?></p>
    <div class="steam-required-actions">
        <a class="btn-steam-sm" href="/auth/steam">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.5 2 2 6.5 2 12l5.4 2.2a2.8 2.8 0 011.6-.5l2.4-3.5v-.1a3.7 3.7 0 113.7 3.7h-.1l-3.4 2.5a2.8 2.8 0 01-5.5.7L2 15.4A10 10 0 1012 2z"/></svg>
            <?= View::e(Lang::t('auth.login_steam')) ?>
        </a>
        <a class="btn btn-ghost" href="/"><?= View::e(Lang::t('err.back_dash')) ?></a>
    </div>
</div></div>
