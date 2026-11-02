<?php
use App\Core\Lang;
use App\Core\View;
?>
<div class="live-search-feedback" data-live-search-feedback="<?= View::e($liveSearchKey) ?>" hidden
     data-search-label="<?= View::e(Lang::t('common.search')) ?>"
     data-loading-label="<?= View::e(Lang::t('search.loading')) ?>"
     data-results-label="<?= View::e(Lang::t('search.results')) ?>"
     data-error-label="<?= View::e(Lang::t('search.failed')) ?>">
    <span data-search-message role="status" aria-live="polite" aria-atomic="true"></span>
    <button type="button" class="btn btn-sm" data-search-retry hidden><?= View::e(Lang::t('search.retry')) ?></button>
    <a class="btn btn-sm" data-search-fallback hidden><?= View::e(Lang::t('search.open_page')) ?></a>
</div>
