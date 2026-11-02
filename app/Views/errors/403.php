<?php use App\Core\Lang; use App\Core\View; $title = Lang::t('err.403_title'); ?>
<div class="card"><div class="empty">
    <div style="font-size:44px;font-weight:800;color:var(--accent-2)">403</div>
    <?= View::e(Lang::t('err.403_msg')) ?>
    <div style="margin-top:16px"><a class="btn" href="/"><?= View::e(Lang::t('err.back_dash')) ?></a></div>
</div></div>
