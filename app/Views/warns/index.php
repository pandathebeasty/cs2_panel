<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var \App\Core\Paginator $warns @var array $filters @var array $servers @var array $avatars @var string $csrf */
$avatars = $avatars ?? [];
$title = Lang::t('nav.warns');
$L = fn(string $k) => View::e(Lang::t($k));
$pg = $warns;
$liveSearchKey = 'warns';
?>
<div class="toolbar">
    <form method="get" data-live-search="warns">
        <input type="text" name="search" placeholder="<?= $L('warns.search_ph') ?>" value="<?= View::e($filters['search']) ?>">
        <select name="status">
            <option value=""><?= $L('common.all_statuses') ?></option>
            <?php foreach (['ACTIVE', 'EXPIRED'] as $st): ?>
                <option value="<?= $st ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><?= $L('common.filter') ?></button>
    </form>
    <?php if (Auth::canManage()): ?>
        <button class="btn btn-primary" data-modal="modal-warn">+ <?= $L('common.new') ?></button>
    <?php endif; ?>
</div>

<?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
<div class="live-search-results" id="search-results-warns" data-live-search-results="warns" tabindex="-1">
<div class="card" style="padding:0">
    <?php if (!$pg->items): ?>
        <div class="empty"><?= $L('warns.none') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th><?= $L('common.player') ?></th><th><?= $L('common.steamid') ?></th><th><?= $L('common.reason') ?></th><th><?= $L('common.admin') ?></th><th><?= $L('common.expires') ?></th><th><?= $L('common.status') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pg->items as $w): ?>
                    <tr>
                        <td class="muted">#<?= (int) $w['id'] ?></td>
                        <td>
                            <?php $sid = (string) ($w['player_steamid'] ?? ''); $valid = Steam::isValidSteamId64($sid); ?>
                            <?php if ($valid): ?>
                                <a class="player-cell" <?= Steam::linkAttrs($sid) ?>>
                                    <img class="player-avatar" src="<?= View::e($avatars[$sid] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                                    <span class="pname"><?= View::e(Steam::displayName($sid, $w['player_name']) ?: Lang::t('common.unknown')) ?></span>
                                </a>
                            <?php else: ?>
                                <span class="pname"><?= View::e($w['player_name'] ?: Lang::t('common.unknown')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mono"><?= View::e($w['player_steamid']) ?></td>
                        <td><?= View::e($w['reason']) ?></td>
                        <td><?= Steam::playerCell((string) ($w['admin_steamid'] ?? ''), (string) $w['admin_name'], $avatars) ?></td>
                        <td class="muted"><?= View::e($w['ends'] ?: '—') ?></td>
                        <td><span class="badge badge-<?= strtolower($w['status']) ?>"><?= View::e($w['status']) ?></span></td>
                        <td class="sanction-actions-cell">
                            <div class="sanction-row-actions">
                            <?php $sanction = $w; $sanctionKind = 'warns'; $removal = null; require BASE_PATH . '/app/Views/partials/sanction-details-button.php'; ?>
                            <?php if ($w['status'] === 'ACTIVE' && Auth::canManage()): ?>
                                <form method="post" action="/warns/<?= (int) $w['id'] ?>/expire" data-confirm="<?= $L('warns.expire_confirm') ?>" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                    <button class="btn btn-sm btn-danger" type="submit"><?= $L('warns.expire') ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if (Auth::canManage()): ?>
                                <form method="post" action="/warns/<?= (int) $w['id'] ?>/delete" data-confirm="<?= $L('common.delete_confirm_row') ?>" style="display:inline">
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
<?php require BASE_PATH . '/app/Views/partials/sanction-details-modal.php'; ?>

<?php if (Auth::canManage()): ?>
<div class="modal-overlay" id="modal-warn">
    <div class="modal">
        <form method="post" action="/warns">
            <div class="modal-head"><h3><?= $L('warns.new_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('bans.player_name') ?></label><input type="text" name="player_name" placeholder="<?= $L('common.optional') ?>"></div>
                <div class="field"><label><?= $L('common.steamid64') ?></label><input type="text" name="player_steamid" required></div>
                <div class="form-row">
                    <div class="field"><label><?= $L('warns.duration') ?></label><input type="number" name="duration" value="10080"></div>
                    <div class="field"><label><?= $L('common.server') ?></label>
                        <select name="server_id"><option value=""><?= $L('common.global') ?></option>
                            <?php foreach ($servers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= View::e($s['hostname']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field"><label><?= $L('common.reason') ?></label><input type="text" name="reason" value="<?= $L('common.no_reason') ?>"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.add') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
