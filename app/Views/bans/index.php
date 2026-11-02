<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var \App\Core\Paginator $bans @var array $filters @var array $servers @var array $avatars @var string $csrf */
$avatars = $avatars ?? [];
$title = Lang::t('nav.bans');
$L = fn(string $k) => View::e(Lang::t($k));
$pg = $bans;
$liveSearchKey = 'bans';
$canBan = Auth::canModerate();
$canUnban = Auth::canModerate();
$isRoot = Auth::isRoot();
// Moderators (and owners) may see the IP column.
$canSeeIp = Auth::canModerate();
?>
<div class="toolbar">
    <form method="get" data-live-search="bans">
        <input type="text" name="search" placeholder="<?= $L('bans.search_ph2') ?>" value="<?= View::e($filters['search']) ?>">
        <select name="status">
            <option value=""><?= $L('common.all_statuses') ?></option>
            <?php foreach (['ACTIVE', 'UNBANNED', 'EXPIRED'] as $st): ?>
                <option value="<?= $st ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><?= $L('common.filter') ?></button>
    </form>
    <div class="toolbar-actions">
        <?php if ($canBan): ?>
            <button class="btn" type="button" data-modal="modal-bulk-bans" data-bulk-open="bans" disabled><?= $L('sanctions.bulk_edit') ?> (<span data-bulk-count="bans">0</span>)</button>
        <?php endif; ?>
        <?php if ($isRoot): ?>
            <button class="btn btn-danger" data-modal="modal-ban-deleteall"><?= $L('common.delete_all') ?></button>
        <?php endif; ?>
        <?php if ($canBan): ?>
            <button class="btn btn-primary" data-modal="modal-ban">+ <?= $L('common.new') ?></button>
        <?php endif; ?>
    </div>
</div>

<?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
<div class="live-search-results" id="search-results-bans" data-live-search-results="bans" tabindex="-1">
<div class="card" style="padding:0">
    <?php if (!$pg->items): ?>
        <div class="empty"><?= $L('bans.none') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table data-bulk-table="bans">
                <thead><tr><?php if ($canBan): ?><th class="bulk-select-cell"><input type="checkbox" data-bulk-all="bans" aria-label="<?= $L('sanctions.bulk_select_page') ?>"></th><?php endif; ?><th>#</th><th><?= $L('common.player') ?></th><th><?= $L('common.steamid') ?></th><?php if ($canSeeIp): ?><th><?= $L('common.ip') ?></th><?php endif; ?><th><?= $L('common.reason') ?></th><th><?= $L('common.admin') ?></th><th><?= $L('common.server') ?></th><th><?= $L('common.expires') ?></th><th><?= $L('common.status') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pg->items as $b): ?>
                    <?php $active = $b['status'] === 'ACTIVE'; ?>
                    <tr>
                        <?php if ($canBan): ?><td class="bulk-select-cell"><input type="checkbox" name="ids[]" value="<?= (int) $b['id'] ?>" form="bulk-edit-bans" data-bulk-row="bans" data-player-name="<?= View::e($b['player_name'] ?: Lang::t('common.unknown')) ?>" aria-label="<?= View::e(Lang::t('sanctions.bulk_select_record', ['id' => (int) $b['id']])) ?>"></td><?php endif; ?>
                        <td class="muted">#<?= (int) $b['id'] ?></td>
                        <td>
                            <?php $sid = (string) ($b['player_steamid'] ?? ''); $valid = Steam::isValidSteamId64($sid); ?>
                            <?php if ($valid): ?>
                                <a class="player-cell" href="<?= View::e(Steam::profileUrl($sid)) ?>" target="_blank" rel="noopener">
                                    <img class="player-avatar" src="<?= View::e($avatars[$sid] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                                    <span class="pname"><?= View::e($b['player_name'] ?: Lang::t('common.unknown')) ?></span>
                                </a>
                            <?php else: ?>
                                <span class="pname"><?= View::e($b['player_name'] ?: Lang::t('common.unknown')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mono"><?= $valid ? Steam::idLink($sid) : View::e($b['player_steamid'] ?: '—') ?></td>
                        <?php if ($canSeeIp): ?><td class="mono"><?= View::e($b['player_ip'] ?: '—') ?></td><?php endif; ?>
                        <td><?= View::e($b['reason']) ?></td>
                        <td><?= Steam::nameLink((string) ($b['admin_steamid'] ?? ''), (string) $b['admin_name']) ?></td>
                        <td class="muted"><?= View::e($b['server_name'] ?: Lang::t('common.global')) ?></td>
                        <td class="muted"><?= $b['duration'] == 0 && $b['status'] !== 'UNBANNED' ? '<span class="badge badge-perm">' . $L('common.permanent') . '</span>' : View::e($b['ends'] ?: '—') ?></td>
                        <td><span class="badge badge-<?= strtolower($b['status']) ?>"><?= View::e($b['status']) ?></span></td>
                        <td class="sanction-actions-cell">
                            <div class="sanction-row-actions">
                            <?php $sanction = $b; $sanctionKind = 'bans'; $removal = $removals[(int) $b['id']] ?? null; require BASE_PATH . '/app/Views/partials/sanction-details-button.php'; ?>
                            <?php if ($canBan): ?>
                                <button class="btn btn-sm"
                                    data-modal="modal-ban-edit"
                                    data-set-ban_id="<?= (int) $b['id'] ?>"
                                    data-set-player_name="<?= View::e($b['player_name'] ?: '') ?>"
                                    data-set-player_steamid="<?= View::e($b['player_steamid'] ?: '') ?>"
                                    data-set-player_ip="<?= View::e($b['player_ip'] ?: '') ?>"
                                    data-set-reason="<?= View::e($b['reason']) ?>"
                                    data-set-duration="<?= (int) $b['duration'] ?>"
                                    data-set-status="<?= View::e($b['status']) ?>"
                                    data-set-server_id="<?= $b['server_id'] === null ? '' : (int) $b['server_id'] ?>"
                                ><?= $L('common.edit') ?></button>
                            <?php endif; ?>
                            <?php if ($canBan && in_array($b['status'], ['UNBANNED', 'EXPIRED'], true)): ?>
                                <button class="btn btn-sm"
                                    data-modal="modal-ban"
                                    data-set-player_name="<?= View::e($b['player_name'] ?: '') ?>"
                                    data-set-player_steamid="<?= View::e($b['player_steamid'] ?: '') ?>"
                                    data-set-player_ip="<?= View::e($b['player_ip'] ?: '') ?>"
                                    data-set-reason="<?= View::e($b['reason']) ?>"
                                ><?= $L('bans.reban') ?></button>
                            <?php endif; ?>
                            <?php if ($active && $canUnban): ?>
                                <button class="btn btn-sm btn-danger" data-modal="modal-unban" data-set-ban_id="<?= (int) $b['id'] ?>"><?= $L('bans.unban') ?></button>
                            <?php endif; ?>
                            <?php if ($canBan): ?>
                                <form method="post" action="/bans/<?= (int) $b['id'] ?>/delete" style="display:inline" data-confirm="<?= $L('common.delete_confirm_row') ?>">
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
<?php if ($canBan): ?>
    <?php $bulkKind = 'bans'; require BASE_PATH . '/app/Views/partials/sanction-bulk-edit.php'; ?>
<?php endif; ?>
<?php require BASE_PATH . '/app/Views/partials/sanction-details-modal.php'; ?>

                                                          
<?php if ($canBan): ?>
<div class="modal-overlay" id="modal-ban">
    <div class="modal">
        <form method="post" action="/bans">
            <div class="modal-head"><h3><?= $L('bans.new_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('bans.player_name') ?></label><input type="text" name="player_name" placeholder="<?= $L('common.optional') ?>"></div>
                <div class="field"><label><?= $L('common.steamid64') ?></label><input type="text" name="player_steamid" placeholder="7656119..."></div>
                <div class="field"><label><?= $L('bans.ip_alt') ?></label><input type="text" name="player_ip" placeholder="<?= $L('common.optional') ?>"></div>
                <div class="field"><label><?= $L('common.reason') ?></label><input type="text" name="reason" value="<?= $L('common.no_reason') ?>"></div>
                <div class="form-row">
                    <div class="field"><label><?= $L('bans.duration_min') ?></label><input type="number" name="duration" value="0"></div>
                    <div class="field"><label><?= $L('common.server') ?></label>
                        <select name="server_id">
                            <option value=""><?= $L('common.global') ?></option>
                            <?php foreach ($servers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= View::e($s['hostname']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.add') ?></button>
            </div>
        </form>
    </div>
</div>

                        
<div class="modal-overlay" id="modal-ban-edit">
    <div class="modal">
        <form method="post" action="/bans/0/update" onsubmit="this.action=(window.CS2_BASE||'')+'/bans/'+this.ban_id.value+'/update'">
            <div class="modal-head"><h3><?= $L('bans.edit_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="ban_id" value="">
                <div class="field"><label><?= $L('bans.player_name') ?></label><input type="text" name="player_name" value="" placeholder="<?= $L('common.optional') ?>"></div>
                <div class="field"><label><?= $L('common.steamid') ?></label><input type="text" name="player_steamid" value="" placeholder="STEAM_0:1:... / 7656119..."></div>
                <div class="field"><label><?= $L('bans.ip_alt') ?></label><input type="text" name="player_ip" value="" placeholder="<?= $L('common.optional') ?>"></div>
                <div class="field"><label><?= $L('common.reason') ?></label><input type="text" name="reason" value=""></div>
                <div class="form-row">
                    <div class="field"><label><?= $L('bans.duration_min') ?></label><input type="number" name="duration" value="0"></div>
                    <div class="field"><label><?= $L('common.status') ?></label>
                        <select name="status">
                            <option value="ACTIVE">ACTIVE</option>
                            <option value="UNBANNED">UNBANNED</option>
                            <!-- Preserve an expired record without offering expiration as an edit action. -->
                            <option value="EXPIRED" hidden>EXPIRED</option>
                        </select>
                    </div>
                </div>
                <div class="field"><label><?= $L('common.server') ?></label>
                    <select name="server_id">
                        <option value=""><?= $L('common.global') ?></option>
                        <?php foreach ($servers as $server): ?><option value="<?= (int) $server['id'] ?>"><?= View::e($server['hostname']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <p class="muted"><?= $L('bans.edit_dur_note') ?></p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.save') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

                     
<?php if ($canUnban): ?>
<div class="modal-overlay" id="modal-unban">
    <div class="modal">
        <form method="post" action="/bans/0/unban" id="unban-form" onsubmit="this.action=(window.CS2_BASE||'')+'/bans/'+this.ban_id.value+'/unban'">
            <div class="modal-head"><h3><?= $L('bans.lift') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="ban_id" value="">
                <div class="field"><label><?= $L('bans.unban_reason') ?></label><input type="text" name="reason" placeholder="<?= $L('bans.no_reason_provided') ?>"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.confirm') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

                                                                               
<?php if ($isRoot): ?>
<div class="modal-overlay" id="modal-ban-deleteall">
    <div class="modal">
        <form method="post" action="/bans/delete-all" id="deleteall-form">
            <div class="modal-head"><h3><?= $L('bans.deleteall_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <p><?= $L('bans.deleteall_warn') ?> <?= $L('common.irreversible') ?></p>
                <label class="inline-check">
                    <input type="checkbox" name="confirm" value="yes" id="deleteall-confirm">
                    <?= $L('bans.deleteall_ack') ?>
                </label>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-danger" id="deleteall-submit" disabled><?= $L('bans.deleteall_title') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var chk = document.getElementById('deleteall-confirm');
    var btn = document.getElementById('deleteall-submit');
    if (chk && btn) {
        chk.addEventListener('change', function () { btn.disabled = !chk.checked; });
    }
    var form = document.getElementById('deleteall-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!chk.checked) { e.preventDefault(); return; }
            if (!window.confirm(<?= json_encode(Lang::t('bans.deleteall_js')) ?>)) { e.preventDefault(); }
        });
    }
})();
</script>
<?php endif; ?>
