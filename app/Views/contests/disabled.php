<?php
use App\Core\Lang;
use App\Core\View;
/** @var bool $needsMigration */
$title = Lang::t('nav.contests');
$needsMigration = $needsMigration ?? false;
?>
<div class="card"><div class="empty">
    <?= View::e($needsMigration ? Lang::t('contests.needs_migration') : Lang::t('contests.disabled')) ?>
</div></div>
