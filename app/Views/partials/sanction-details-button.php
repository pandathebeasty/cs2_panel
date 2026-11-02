<span class="sanction-details-trigger">
    <button type="button" class="btn btn-sm" data-modal="modal-sanction-details" data-sanction-details
        aria-haspopup="dialog" aria-controls="modal-sanction-details"
        aria-label="<?= \App\Core\View::e(\App\Core\Lang::t('sanctions.details_record', ['id' => (int) $sanction['id']])) ?>"
    ><?= \App\Core\View::e(\App\Core\Lang::t('sanctions.details')) ?></button>
    <template data-sanction-details-template><?php require BASE_PATH . '/app/Views/partials/sanction-details.php'; ?></template>
</span>
