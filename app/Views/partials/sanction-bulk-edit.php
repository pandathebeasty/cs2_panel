<?php
use App\Core\Lang;
use App\Core\View;
use App\Models\SanctionBulkModel;

?>
<div class="modal-overlay" id="modal-bulk-<?= View::e($bulkKind) ?>">
    <div class="modal">
        <form method="post" action="/<?= View::e($bulkKind) ?>/bulk-update"
              id="bulk-edit-<?= View::e($bulkKind) ?>" data-bulk-kind="<?= View::e($bulkKind) ?>"
              data-selection-error="<?= View::e(Lang::t('sanctions.bulk_selection_invalid', ['max' => SanctionBulkModel::MAX_SELECTION])) ?>"
              data-fields-error="<?= View::e(Lang::t('sanctions.bulk_fields_required')) ?>">
            <div class="modal-head">
                <h3><?= View::e(Lang::t('sanctions.bulk_title_' . $bulkKind)) ?></h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <p class="bulk-selection-heading"><?= View::e(Lang::t('sanctions.bulk_selected')) ?>: <strong data-bulk-count="<?= View::e($bulkKind) ?>">0</strong></p>
                <ul class="bulk-selection-list" data-bulk-list tabindex="0" aria-label="<?= View::e(Lang::t('sanctions.bulk_selected')) ?>"></ul>
                <div class="field">
                    <label for="bulk-action-<?= View::e($bulkKind) ?>"><?= View::e(Lang::t('sanctions.bulk_action')) ?></label>
                    <select id="bulk-action-<?= View::e($bulkKind) ?>" name="bulk_action" data-bulk-action>
                        <?php foreach (SanctionBulkModel::ACTIONS[$bulkKind] as $action): ?>
                            <option value="<?= $action ?>" data-bulk-action-mode="<?= SanctionBulkModel::actionMode($action) ?>"><?= View::e(Lang::t('sanctions.bulk_action_' . $action)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="hint" data-bulk-hint="edit"><?= View::e(Lang::t('sanctions.bulk_hint')) ?></p>
                <p class="hint" data-bulk-hint="create" hidden><?= View::e(Lang::t('sanctions.bulk_create_hint')) ?></p>
                <p class="hint" data-bulk-hint="lift" hidden><?= View::e(Lang::t('sanctions.bulk_lift_hint')) ?></p>

                <div class="bulk-edit-field" data-bulk-field-modes="edit create lift">
                    <label class="inline-check"><input type="checkbox" name="apply[reason]" value="1" data-bulk-apply="reason"> <span data-bulk-reason-label data-edit-label="<?= View::e(Lang::t('sanctions.bulk_apply_reason')) ?>" data-lift-label="<?= View::e(Lang::t('sanctions.bulk_apply_lift_reason')) ?>"><?= View::e(Lang::t('sanctions.bulk_apply_reason')) ?></span></label>
                    <input type="text" name="reason" data-bulk-value="reason" maxlength="255" required disabled aria-label="<?= View::e(Lang::t('common.reason')) ?>">
                </div>
                <div class="bulk-edit-field" data-bulk-field-modes="edit create">
                    <label class="inline-check"><input type="checkbox" name="apply[duration]" value="1" data-bulk-apply="duration"> <?= View::e(Lang::t('sanctions.bulk_apply_duration')) ?></label>
                    <input type="number" name="duration" data-bulk-value="duration" min="0" max="<?= SanctionBulkModel::MAX_DURATION ?>" step="1" value="0" required disabled aria-label="<?= View::e(Lang::t('bans.duration_min')) ?>">
                    <p class="hint" data-bulk-hint="edit"><?= View::e(Lang::t('sanctions.bulk_duration_hint')) ?></p>
                    <p class="hint" data-bulk-hint="create" hidden><?= View::e(Lang::t('sanctions.bulk_create_duration_hint')) ?></p>
                </div>
                <div class="bulk-edit-field" data-bulk-field-modes="edit create">
                    <label class="inline-check"><input type="checkbox" name="apply[server_id]" value="1" data-bulk-apply="server_id"> <?= View::e(Lang::t('sanctions.bulk_apply_server')) ?></label>
                    <select name="server_id" data-bulk-value="server_id" disabled aria-label="<?= View::e(Lang::t('common.server')) ?>">
                        <option value=""><?= View::e(Lang::t('common.global')) ?></option>
                        <?php foreach ($servers as $server): ?>
                            <option value="<?= (int) $server['id'] ?>"><?= View::e($server['hostname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="inline-check bulk-edit-confirm">
                    <input type="checkbox" name="confirm_bulk" value="1" required>
                    <?= View::e(Lang::t('sanctions.bulk_confirm')) ?>
                </label>
                <p class="bulk-form-error" data-bulk-error role="alert" hidden></p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= View::e(Lang::t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary" data-bulk-submit disabled><?= View::e(Lang::t('common.confirm')) ?></button>
            </div>
        </form>
    </div>
</div>
