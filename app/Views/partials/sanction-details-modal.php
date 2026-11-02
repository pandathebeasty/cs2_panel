<div class="modal-overlay" id="modal-sanction-details">
    <div class="modal sanction-details-modal" role="dialog" aria-modal="true" aria-labelledby="sanction-details-title" tabindex="-1">
        <div class="modal-head">
            <h3 id="sanction-details-title"><?= \App\Core\View::e(\App\Core\Lang::t('sanctions.details_title')) ?></h3>
            <button type="button" class="modal-close" aria-label="<?= \App\Core\View::e(\App\Core\Lang::t('common.close')) ?>">&times;</button>
        </div>
        <div class="modal-body" data-sanction-details-content></div>
        <div class="modal-foot">
            <button type="button" class="btn modal-close"><?= \App\Core\View::e(\App\Core\Lang::t('common.close')) ?></button>
        </div>
    </div>
</div>
