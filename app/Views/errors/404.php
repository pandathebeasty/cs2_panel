<?php use App\Core\Lang; use App\Core\View; $title = '404'; ?>
<div class="card"><div class="empty">
    <div style="font-size:44px;font-weight:800;color:var(--accent-2)">404</div>
    <?= View::e(Lang::t('err.404_msg')) ?>
    <div style="margin-top:16px"><a class="btn" href="/"><?= View::e(Lang::t('err.back_dash')) ?></a></div>
</div></div>
