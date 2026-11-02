<?php
use App\Core\Lang;
use App\Core\View;
?>
<button class="btn btn-icon effects-toggle" type="button" data-effects-toggle
        data-auto-label="<?= View::e(Lang::t('effects.auto')) ?>"
        data-full-label="<?= View::e(Lang::t('effects.full')) ?>"
        data-reduced-label="<?= View::e(Lang::t('effects.reduced')) ?>"
        data-auto-reduced-label="<?= View::e(Lang::t('effects.auto_reduced')) ?>"
        data-effects-hint="<?= View::e(Lang::t('effects.hint')) ?>"
        aria-label="<?= View::e(Lang::t('effects.auto')) ?>"
        title="<?= View::e(Lang::t('effects.auto')) ?>">
    <span data-effects-indicator aria-hidden="true">Auto</span>
</button>
