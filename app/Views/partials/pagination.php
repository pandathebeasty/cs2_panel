<?php
/** @var \App\Core\Paginator $pg */
use App\Core\Lang;
use App\Core\View;

if (isset($liveSearchKey) && isset($pg)):
?>
<span data-live-search-meta data-total="<?= (int) $pg->total ?>" data-page="<?= (int) $pg->page ?>" hidden></span>
<?php
endif;
if (!isset($pg) || $pg->lastPage <= 1) {
    return;
}

// keep the current query string (filters) and change only the page
$params = $_GET;
$url = function (int $p) use ($params): string {
    $params['page'] = $p;
    return '?' . http_build_query($params);
};

$start = max(1, $pg->page - 2);
$end   = min($pg->lastPage, $pg->page + 2);
?>
<div class="pagination">
    <a class="<?= $pg->page <= 1 ? 'disabled' : '' ?>" href="<?= View::e($url(max(1, $pg->page - 1))) ?>">‹ <?= View::e(Lang::t('common.prev')) ?></a>

    <?php if ($start > 1): ?>
        <a href="<?= View::e($url(1)) ?>">1</a>
        <?php if ($start > 2): ?><span class="disabled">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i === $pg->page): ?>
            <span class="current"><?= $i ?></span>
        <?php else: ?>
            <a href="<?= View::e($url($i)) ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($end < $pg->lastPage): ?>
        <?php if ($end < $pg->lastPage - 1): ?><span class="disabled">…</span><?php endif; ?>
        <a href="<?= View::e($url($pg->lastPage)) ?>"><?= $pg->lastPage ?></a>
    <?php endif; ?>

    <a class="<?= $pg->page >= $pg->lastPage ? 'disabled' : '' ?>" href="<?= View::e($url(min($pg->lastPage, $pg->page + 1))) ?>"><?= View::e(Lang::t('common.next')) ?> ›</a>

    <span class="disabled" style="margin-left:auto;background:transparent;border:none;color:var(--text-mut)">
        <?= number_format($pg->total) ?> <?= View::e(Lang::t('common.results')) ?>
    </span>
</div>
