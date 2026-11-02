<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var \App\Core\Paginator $reports @var array $filters @var bool $isStaff @var array $servers @var array $avatars @var string $csrf */
$title = Lang::t('nav.reports');
$L = fn(string $k) => View::e(Lang::t($k));
$pg = $reports;
$liveSearchKey = 'reports';
$avatars = $avatars ?? [];

$statusBadge = ['open' => 'badge-active', 'accepted' => 'badge-mute', 'rejected' => 'badge-expired'];
?>
<div class="toolbar">
    <form method="get" data-live-search="reports">
        <input type="text" name="search" placeholder="<?= View::e(Lang::t('common.search')) ?>..." value="<?= View::e($filters['search']) ?>">
        <select name="status">
            <option value=""><?= View::e(Lang::t('common.all')) ?></option>
            <?php foreach (\App\Models\ReportModel::STATUSES as $st): ?>
                <option value="<?= View::e($st) ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= View::e(Lang::t('reports.status_' . $st)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><?= View::e(Lang::t('common.filter')) ?></button>
    </form>
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal="modal-report-new">+ <?= $L('reports.new') ?></button>
</div>

<?php if (!$isStaff): ?>
    <div class="card" style="margin-bottom:18px"><div style="padding:12px 16px" class="muted"><?= $L('reports.intro') ?></div></div>
<?php endif; ?>

<?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
<div class="live-search-results" id="search-results-reports" data-live-search-results="reports" tabindex="-1">
<div class="card" style="padding:0">
    <?php if (!$pg->items): ?>
        <div class="empty"><?= $L('reports.none') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th><?= $L('reports.target') ?></th>
                    <?php if ($isStaff): ?><th><?= $L('reports.reporter') ?></th><?php endif; ?>
                    <th><?= $L('reports.type') ?></th>
                    <th><?= $L('reports.message') ?></th>
                    <th><?= View::e(Lang::t('common.status')) ?></th>
                    <th><?= View::e(Lang::t('common.created')) ?></th>
                    <?php if ($isStaff): ?><th></th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($pg->items as $r): ?>
                    <tr>
                        <td><?= Steam::playerCell((string) $r['target_steamid'], (string) ($r['target_name'] ?? ''), $avatars) ?></td>
                        <?php if ($isStaff): ?>
                            <td><?= Steam::playerCell((string) $r['reporter_steamid'], (string) ($r['reporter_name'] ?? ''), $avatars) ?></td>
                        <?php endif; ?>
                        <td><span class="badge badge-role-admin"><?= View::e(Lang::t('reports.type_' . $r['type'])) ?></span></td>
                        <td style="max-width:400px;white-space:normal"><?= nl2br(View::e($r['message'])) ?>
                            <?php if (!empty($r['admin_reply'])): ?>
                                <div class="appeal-reply">
                                    <span class="muted"><?= $L('reports.reply') ?><?= !empty($r['handled_name']) ? ' · ' . View::e($r['handled_name']) : '' ?>:</span>
                                    <div><?= nl2br(View::e($r['admin_reply'])) ?></div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= $statusBadge[$r['status']] ?? 'badge-mute' ?>"><?= View::e(Lang::t('reports.status_' . $r['status'])) ?></span></td>
                        <td class="muted"><?= View::e((string) $r['created_at']) ?></td>
                        <?php if ($isStaff): ?>
                            <td style="white-space:nowrap">
                                <button class="btn btn-sm"
                                    data-modal="modal-report-resolve"
                                    data-report-type="<?= View::e((string) $r['type']) ?>"
                                    data-set-admin_reply="<?= View::e((string) ($r['admin_reply'] ?? '')) ?>"
                                    onclick="document.getElementById('report-resolve-form').action=(window.CS2_BASE||'')+'/reports/<?= (int) $r['id'] ?>/resolve'"><?= $L('reports.handle') ?></button>
                                <?php if (App\Core\Auth::isRoot()): ?>
                                    <form method="post" action="/reports/<?= (int) $r['id'] ?>/delete" style="display:inline" data-confirm="<?= $L('reports.delete_confirm') ?>">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <button class="btn btn-sm btn-danger" type="submit"><?= View::e(Lang::t('common.delete')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require BASE_PATH . '/app/Views/partials/pagination.php'; ?>
</div>

<!-- New report -->
<div class="modal-overlay" id="modal-report-new">
    <div class="modal">
        <form method="post" action="/reports">
            <div class="modal-head"><h3><?= $L('reports.new') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field">
                    <label><?= $L('reports.target_steamid') ?></label>
                    <input type="text" name="target_steamid" placeholder="STEAM_0:1:... / 7656119..." required>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label><?= $L('reports.type') ?></label>
                        <select name="type">
                            <?php foreach (\App\Models\ReportModel::TYPES as $ty): ?>
                                <option value="<?= View::e($ty) ?>"><?= View::e(Lang::t('reports.type_' . $ty)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label><?= View::e(Lang::t('common.server')) ?></label>
                        <select name="server_id">
                            <option value=""><?= View::e(Lang::t('common.all_servers')) ?></option>
                            <?php foreach ($servers as $sv): ?>
                                <option value="<?= (int) $sv['id'] ?>"><?= View::e($sv['hostname'] ?: $sv['address']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label><?= $L('reports.message') ?></label>
                    <textarea name="message" rows="6" required minlength="20" placeholder="<?= $L('reports.message_ph') ?>"></textarea>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= View::e(Lang::t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('reports.submit') ?></button>
            </div>
        </form>
    </div>
</div>

<?php if ($isStaff): ?>
                       
<div class="modal-overlay" id="modal-report-resolve">
    <div class="modal">
        <form method="post" action="/reports/0/resolve" id="report-resolve-form">
            <div class="modal-head"><h3><?= $L('reports.handle') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field">
                    <label><?= View::e(Lang::t('common.status')) ?></label>
                    <select name="status" id="report-resolve-status">
                        <?php foreach (\App\Models\ReportModel::STATUSES as $st): ?>
                            <option value="<?= View::e($st) ?>"><?= View::e(Lang::t('reports.status_' . $st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="report-action-options" style="display:none">
                    <label class="check" style="margin-bottom:10px">
                        <input type="checkbox" name="apply_action" value="1" id="report-apply-action">
                        <span><?= $L('reports.apply_action') ?></span>
                    </label>
                    <p class="hint" style="margin:-4px 0 12px"><?= $L('reports.apply_action_hint') ?></p>
                    <div id="report-action-fields" style="display:none">
                        <div class="field">
                            <label><?= View::e(Lang::t('common.duration')) ?></label>
                            <input type="number" name="action_duration" value="60" min="0" max="525600">
                            <p class="hint" style="margin-top:4px"><?= $L('reports.action_duration_hint') ?></p>
                        </div>
                        <div class="field">
                            <label><?= View::e(Lang::t('common.reason')) ?></label>
                            <input type="text" name="action_reason" maxlength="255" placeholder="<?= View::e(Lang::t('common.optional')) ?>">
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label><?= $L('reports.reply') ?></label>
                    <textarea name="admin_reply" rows="5" placeholder="<?= View::e(Lang::t('common.optional')) ?>"></textarea>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= View::e(Lang::t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= View::e(Lang::t('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var form = document.getElementById('report-resolve-form');
    var status = document.getElementById('report-resolve-status');
    var options = document.getElementById('report-action-options');
    var check = document.getElementById('report-apply-action');
    var fields = document.getElementById('report-action-fields');
    if (!form || !status || !options || !check || !fields) return;

    function update() {
        var canApply = status.value === 'accepted' && form.dataset.reportType !== 'other';
        options.style.display = canApply ? '' : 'none';
        fields.style.display = canApply && check.checked ? '' : 'none';
        if (!canApply) check.checked = false;
    }
    document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-modal="modal-report-resolve"]');
            if (!button) return;
            form.dataset.reportType = button.dataset.reportType || 'other';
            status.value = 'open';
            check.checked = false;
            form.elements.action_duration.value = '60';
            form.elements.action_reason.value = '';
            form.elements.admin_reply.value = '';
            update();
    }, true);
    status.addEventListener('change', update);
    check.addEventListener('change', update);
    update();
})();
</script>
<?php endif; ?>
