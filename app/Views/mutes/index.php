<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var \App\Core\Paginator $mutes @var array $filters @var array $servers @var array $avatars @var string $csrf */
$avatars = $avatars ?? [];
$title = Lang::t('nav.mutes');
$L = fn(string $k) => View::e(Lang::t($k));
$pg = $mutes;
$liveSearchKey = 'mutes';
?>
<div class="toolbar">
    <form method="get" data-live-search="mutes">
        <input type="text" name="search" placeholder="<?= $L('mutes.search_ph') ?>" value="<?= View::e($filters['search']) ?>">
        <select name="type">
            <option value=""><?= $L('mutes.all_types') ?></option>
            <?php foreach (['GAG', 'MUTE', 'SILENCE'] as $t): ?>
                <option value="<?= $t ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value=""><?= $L('common.all_statuses') ?></option>
            <?php foreach (['ACTIVE', 'UNMUTED', 'EXPIRED'] as $st): ?>
                <option value="<?= $st ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><?= $L('common.filter') ?></button>
    </form>
    <?php if (Auth::can('@css/chat')): ?>
            <button class="btn" type="button" data-modal="modal-bulk-mutes" data-bulk-open="mutes" disabled><?= $L('sanctions.bulk_edit') ?> (<span data-bulk-count="mutes">0</span>)</button>
        <button class="btn btn-primary" data-modal="modal-mute">+ <?= $L('common.new') ?></button>
    <?php endif; ?>
</div>

<?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
<div class="live-search-results" id="search-results-mutes" data-live-search-results="mutes" tabindex="-1">
<div class="card" style="padding:0">
    <?php if (!$pg->items): ?>
        <div class="empty"><?= $L('mutes.none') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table data-bulk-table="mutes">
                <thead><tr><?php if (Auth::can('@css/chat')): ?><th class="bulk-select-cell"><input type="checkbox" data-bulk-all="mutes" aria-label="<?= $L('sanctions.bulk_select_page') ?>"></th><?php endif; ?><th>#</th><th><?= $L('common.player') ?></th><th><?= $L('common.steamid') ?></th><th><?= $L('common.type') ?></th><th><?= $L('common.reason') ?></th><th><?= $L('common.admin') ?></th><th><?= $L('common.expires') ?></th><th><?= $L('common.status') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pg->items as $m): ?>
                    <tr>
                        <?php if (Auth::can('@css/chat')): ?><td class="bulk-select-cell"><input type="checkbox" name="ids[]" value="<?= (int) $m['id'] ?>" form="bulk-edit-mutes" data-bulk-row="mutes" data-player-name="<?= View::e($m['player_name'] ?: Lang::t('common.unknown')) ?>" aria-label="<?= View::e(Lang::t('sanctions.bulk_select_record', ['id' => (int) $m['id']])) ?>"></td><?php endif; ?>
                        <td class="muted">#<?= (int) $m['id'] ?></td>
                        <td>
                            <?php $sid = (string) ($m['player_steamid'] ?? ''); $valid = Steam::isValidSteamId64($sid); ?>
                            <?php if ($valid): ?>
                                <a class="player-cell" <?= Steam::linkAttrs($sid) ?>>
                                    <img class="player-avatar" src="<?= View::e($avatars[$sid] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                                    <span class="pname"><?= View::e($m['player_name'] ?: Lang::t('common.unknown')) ?></span>
                                </a>
                            <?php else: ?>
                                <span class="pname"><?= View::e($m['player_name'] ?: Lang::t('common.unknown')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mono"><?= View::e($m['player_steamid']) ?></td>
                        <td><span class="badge badge-<?= strtolower($m['type']) ?>"><?= View::e($m['type']) ?></span></td>
                        <td><?= View::e($m['reason']) ?></td>
                        <td><?= Steam::playerCell((string) ($m['admin_steamid'] ?? ''), (string) $m['admin_name'], $avatars) ?></td>
                        <td class="muted"><?= $m['duration'] == 0 && $m['status'] !== 'UNMUTED' ? '<span class="badge badge-perm">' . $L('common.permanent') . '</span>' : View::e($m['ends'] ?: '—') ?></td>
                        <td><span class="badge badge-<?= strtolower($m['status']) ?>"><?= View::e($m['status']) ?></span></td>
                        <td class="sanction-actions-cell">
                            <div class="sanction-row-actions">
                            <?php $sanction = $m; $sanctionKind = 'mutes'; $removal = $removals[(int) $m['id']] ?? null; require BASE_PATH . '/app/Views/partials/sanction-details-button.php'; ?>
                            <?php if ($m['status'] === 'ACTIVE' && Auth::can('@css/chat')): ?>
                                <button class="btn btn-sm btn-danger" data-modal="modal-unmute" data-set-mute_id="<?= (int) $m['id'] ?>"><?= $L('mutes.lift') ?></button>
                            <?php endif; ?>
                            <?php if (Auth::can('@css/chat')): ?>
                                <form method="post" action="/mutes/<?= (int) $m['id'] ?>/delete" style="display:inline" data-confirm="<?= $L('common.delete_confirm_row') ?>">
                                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                    <button class="btn btn-sm btn-danger" type="submit"><?= $L('common.delete') ?></button>
                                </form>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require BASE_PATH . '/app/Views/partials/pagination.php'; ?>
</div>
<?php if (Auth::can('@css/chat')): ?>
    <?php $bulkKind = 'mutes'; require BASE_PATH . '/app/Views/partials/sanction-bulk-edit.php'; ?>
<?php endif; ?>
<?php require BASE_PATH . '/app/Views/partials/sanction-details-modal.php'; ?>

<?php if (Auth::can('@css/chat')): ?>
<div class="modal-overlay" id="modal-mute">
    <div class="modal">
        <form method="post" action="/mutes">
            <div class="modal-head"><h3><?= $L('mutes.new_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('bans.player_name') ?></label><input type="text" name="player_name" placeholder="<?= $L('common.optional') ?>"></div>
                <div class="field"><label><?= $L('common.steamid64') ?></label><input type="text" name="player_steamid" placeholder="7656119..." required></div>
                <div class="form-row">
                    <div class="field"><label><?= $L('common.type') ?></label>
                        <select name="type"><option value="GAG"><?= $L('mutes.gag') ?></option><option value="MUTE"><?= $L('mutes.mute') ?></option><option value="SILENCE"><?= $L('mutes.silence') ?></option></select>
                    </div>
                    <div class="field"><label><?= $L('mutes.duration') ?></label><input type="number" name="duration" value="0"></div>
                </div>
                <div class="field"><label><?= $L('common.reason') ?></label><input type="text" name="reason" value="<?= $L('common.no_reason') ?>"></div>
                <div class="field"><label><?= $L('common.server') ?></label>
                    <select name="server_id"><option value=""><?= $L('common.global') ?></option>
                        <?php foreach ($servers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= View::e($s['hostname']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.add') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-unmute">
    <div class="modal">
        <form method="post" action="/mutes/0/unmute" onsubmit="this.action=(window.CS2_BASE||'')+'/mutes/'+this.mute_id.value+'/unmute'">
            <div class="modal-head"><h3><?= $L('mutes.lift_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="mute_id" value="">
                <div class="field"><label><?= $L('common.reason') ?></label><input type="text" name="reason" placeholder="<?= $L('bans.no_reason_provided') ?>"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.confirm') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
