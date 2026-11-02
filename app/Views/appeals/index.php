<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var \App\Core\Paginator $appeals @var array $filters @var bool $isStaff @var array $activeBans @var array $avatars @var ?array $me @var string $csrf */
$title = Lang::t('nav.appeals');
$L = fn(string $k) => View::e(Lang::t($k));
$pg = $appeals;
$liveSearchKey = 'appeals';
$avatars = $avatars ?? [];
$activeBans = $activeBans ?? [];

$statusBadge = ['open' => 'badge-active', 'approved' => 'badge-mute', 'rejected' => 'badge-expired'];
$banDuration = static function (int $minutes): string {
    if ($minutes <= 0) return Lang::t('common.permanent');
    if ($minutes % 1440 === 0) { $n = intdiv($minutes, 1440); return $n . ' ' . Lang::t($n === 1 ? 'time.day' : 'time.days'); }
    if ($minutes % 60 === 0) { $n = intdiv($minutes, 60); return $n . ' ' . Lang::t($n === 1 ? 'time.hour' : 'time.hours'); }
    return $minutes . ' ' . Lang::t($minutes === 1 ? 'time.minute' : 'time.minutes');
};
?>
<div class="toolbar">
    <form method="get" data-live-search="appeals">
        <input type="text" name="search" placeholder="<?= View::e(Lang::t('common.search')) ?>..." value="<?= View::e($filters['search']) ?>">
        <select name="status">
            <option value=""><?= View::e(Lang::t('common.all')) ?></option>
            <?php foreach (\App\Models\AppealModel::STATUSES as $st): ?>
                <option value="<?= View::e($st) ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= View::e(Lang::t('appeals.status_' . $st)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><?= View::e(Lang::t('common.filter')) ?></button>
    </form>
    <div class="spacer"></div>
    <?php if ($activeBans): ?>
        <button class="btn btn-primary" data-modal="modal-appeal-new">+ <?= $L('appeals.new') ?></button>
    <?php endif; ?>
</div>

<?php if (!$isStaff): ?>
    <div class="card" style="margin-bottom:18px"><div style="padding:12px 16px" class="muted"><?= $L('appeals.intro') ?></div></div>
<?php endif; ?>
<?php if (!$activeBans): ?>
    <div class="card" style="margin-bottom:18px"><div style="padding:12px 16px" class="muted"><?= $L('appeals.no_active_ban') ?></div></div>
<?php endif; ?>

<?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
<div class="live-search-results" id="search-results-appeals" data-live-search-results="appeals" tabindex="-1">
<div class="card" style="padding:0">
    <?php if (!$pg->items): ?>
        <div class="empty"><?= $L('appeals.none') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th><?= View::e(Lang::t('common.player')) ?></th>
                    <th><?= $L('appeals.ban_details') ?></th>
                    <th><?= $L('appeals.message') ?></th>
                    <th><?= View::e(Lang::t('common.status')) ?></th>
                    <th><?= View::e(Lang::t('common.created')) ?></th>
                    <?php if ($isStaff): ?><th></th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($pg->items as $a): ?>
                    <tr>
                        <td><?= Steam::playerCell((string) $a['steamid64'], (string) ($a['player_name'] ?? ''), $avatars) ?></td>
                        <td class="appeal-ban-details">
                            <div><strong><?= $L('appeals.banned_by') ?>:</strong> <?= View::e((string) ($a['ban_admin_name'] ?? '—')) ?></div>
                            <div><strong><?= $L('appeals.ban_reason') ?>:</strong> <?= View::e((string) ($a['ban_reason'] ?? '—')) ?></div>
                            <div><strong><?= $L('appeals.ban_duration') ?>:</strong> <?= View::e($banDuration((int) ($a['ban_duration'] ?? 0))) ?></div>
                            <?php if (!empty($a['ban_ends'])): ?><div><strong><?= $L('common.expires') ?>:</strong> <?= View::e((string) $a['ban_ends']) ?></div><?php endif; ?>
                        </td>
                        <td style="max-width:420px;white-space:normal"><?= nl2br(View::e($a['message'])) ?>
                            <?php if (!empty($a['admin_reply'])): ?>
                                <div class="appeal-reply">
                                    <span class="muted"><?= $L('appeals.reply') ?><?= !empty($a['handled_name']) ? ' · ' . View::e($a['handled_name']) : '' ?>:</span>
                                    <div><?= nl2br(View::e($a['admin_reply'])) ?></div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= $statusBadge[$a['status']] ?? 'badge-mute' ?>"><?= View::e(Lang::t('appeals.status_' . $a['status'])) ?></span></td>
                        <td class="muted"><?= View::e((string) $a['created_at']) ?></td>
                        <?php if ($isStaff): ?>
                            <td style="white-space:nowrap">
                                <button class="btn btn-sm"
                                    data-modal="modal-appeal-resolve"
                                    data-set-admin_reply="<?= View::e((string) ($a['admin_reply'] ?? '')) ?>"
                                    onclick="document.getElementById('appeal-resolve-form').action=(window.CS2_BASE||'')+'/appeals/<?= (int) $a['id'] ?>/resolve'"><?= $L('appeals.handle') ?></button>
                                <?php if (App\Core\Auth::isRoot()): ?>
                                    <form method="post" action="/appeals/<?= (int) $a['id'] ?>/delete" style="display:inline" data-confirm="<?= $L('appeals.delete_confirm') ?>">
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

<!-- Appeal one of the player's active bans. -->
<?php if ($activeBans): ?>
<div class="modal-overlay" id="modal-appeal-new">
    <div class="modal">
        <form method="post" action="/appeals">
            <div class="modal-head"><h3><?= $L('appeals.new') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <p class="muted" style="margin-top:0"><?= $L('appeals.form_hint') ?></p>
                <div class="field">
                    <label><?= $L('appeals.select_ban') ?></label>
                    <select name="ban_id" id="appeal-ban-select" required>
                        <?php foreach ($activeBans as $index => $ban): ?>
                            <?php $optionLabel = '#' . (int) $ban['id'] . ' · ' . (string) ($ban['admin_name'] ?: Lang::t('common.none')) . ' · ' . $ban['duration_label']; ?>
                            <option value="<?= (int) $ban['id'] ?>"
                                data-admin="<?= View::e((string) ($ban['admin_name'] ?? '')) ?>"
                                data-reason="<?= View::e((string) ($ban['reason'] ?? '')) ?>"
                                data-duration="<?= View::e((string) $ban['duration_label']) ?>"
                                data-ends="<?= View::e((string) ($ban['ends'] ?? '')) ?>"
                                <?= $index === 0 ? 'selected' : '' ?>><?= View::e($optionLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <dl class="appeal-ban-snapshot">
                    <dt><?= $L('appeals.banned_by') ?></dt><dd id="appeal-ban-admin"></dd>
                    <dt><?= $L('appeals.ban_reason') ?></dt><dd id="appeal-ban-reason"></dd>
                    <dt><?= $L('appeals.ban_duration') ?></dt><dd id="appeal-ban-duration"></dd>
                    <dt><?= View::e(Lang::t('common.expires')) ?></dt><dd id="appeal-ban-ends"></dd>
                </dl>
                <div class="field">
                    <label><?= $L('appeals.message') ?></label>
                    <textarea name="message" rows="6" required minlength="20" placeholder="<?= $L('appeals.message_ph') ?>"></textarea>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= View::e(Lang::t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('appeals.submit') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var select = document.getElementById('appeal-ban-select');
    if (!select) return;
    var labels = {
        none: <?= json_encode(Lang::t('common.none'), JSON_UNESCAPED_UNICODE) ?>,
        noReason: <?= json_encode(Lang::t('appeals.no_ban_reason'), JSON_UNESCAPED_UNICODE) ?>,
        noExpiry: <?= json_encode(Lang::t('common.permanent'), JSON_UNESCAPED_UNICODE) ?>
    };
    function updateBanDetails() {
        var option = select.options[select.selectedIndex];
        document.getElementById('appeal-ban-admin').textContent = option.dataset.admin || labels.none;
        document.getElementById('appeal-ban-reason').textContent = option.dataset.reason || labels.noReason;
        document.getElementById('appeal-ban-duration').textContent = option.dataset.duration || '';
        document.getElementById('appeal-ban-ends').textContent = option.dataset.ends || labels.noExpiry;
    }
    select.addEventListener('change', updateBanDetails);
    updateBanDetails();
})();
</script>
<?php endif; ?>

<?php if ($isStaff): ?>
                       
<div class="modal-overlay" id="modal-appeal-resolve">
    <div class="modal">
        <form method="post" action="/appeals/0/resolve" id="appeal-resolve-form">
            <div class="modal-head"><h3><?= $L('appeals.handle') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field">
                    <label><?= View::e(Lang::t('common.status')) ?></label>
                    <select name="status" id="appeal-resolve-status">
                        <?php foreach (\App\Models\AppealModel::STATUSES as $st): ?>
                            <option value="<?= View::e($st) ?>"><?= View::e(Lang::t('appeals.status_' . $st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="appeal-unban-options" style="display:none">
                    <label class="check" style="margin-bottom:10px">
                        <input type="checkbox" name="unban" value="1" id="appeal-unban-check">
                        <span><?= $L('appeals.unban_question') ?></span>
                    </label>
                    <div class="field" id="appeal-unban-reason-field" style="display:none">
                        <label><?= $L('appeals.unban_reason') ?></label>
                        <input type="text" name="unban_reason" maxlength="255" placeholder="<?= View::e(Lang::t('common.optional')) ?>">
                    </div>
                </div>
                <div class="field">
                    <label><?= $L('appeals.reply') ?></label>
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
    var status = document.getElementById('appeal-resolve-status');
    var options = document.getElementById('appeal-unban-options');
    var check = document.getElementById('appeal-unban-check');
    var reason = document.getElementById('appeal-unban-reason-field');
    if (!status || !options || !check || !reason) return;
    function update() {
        options.style.display = status.value === 'approved' ? '' : 'none';
        reason.style.display = status.value === 'approved' && check.checked ? '' : 'none';
        if (status.value !== 'approved') check.checked = false;
    }
    status.addEventListener('change', update);
    check.addEventListener('change', update);
    document.addEventListener('click', function (event) {
            if (!event.target.closest('[data-modal="modal-appeal-resolve"]')) return;
            var form = document.getElementById('appeal-resolve-form');
            status.value = 'open';
            check.checked = false;
            form.elements.unban_reason.value = '';
            form.elements.admin_reply.value = '';
            update();
    }, true);
    update();
})();
</script>
<?php endif; ?>
