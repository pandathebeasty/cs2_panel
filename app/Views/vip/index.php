<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var \App\Core\Paginator $vips @var array $filters @var array $groups @var array $vipGroups @var array $servers @var string $csrf */
$title = Lang::t('nav.vip');
$L = fn(string $k) => View::e(Lang::t($k));
$pg = $vips;
$liveSearchKey = 'vip';
$canManage = Auth::canManage();
$vipGroups = $vipGroups ?? [];
// Steam avatars for the listed VIPs (batch; needs steam.api_key).
$avatars = $pg->items ? Steam::avatars(array_column($pg->items, 'steamid64')) : [];

// Group selector: a dropdown of the groups defined in Settings. If none are
// configured, fall back to a free-text input so VIP still works out of the box.
$groupField = function (string $selected = '') use ($vipGroups): string {
    if (!$vipGroups) {
        $val = $selected !== '' ? $selected : 'VIP';
        return '<input type="text" name="group" value="' . View::e($val) . '">';
    }
    $h = '<select name="group">';
    foreach ($vipGroups as $g) {
        $sel = ($g === $selected) ? ' selected' : '';
        $h .= '<option value="' . View::e($g) . '"' . $sel . '>' . View::e($g) . '</option>';
    }
    $h .= '</select>';
    return $h;
};
?>
<div class="toolbar">
    <form method="get" data-live-search="vip">
        <input type="text" name="search" placeholder="<?= $L('vip.search_ph') ?>" value="<?= View::e($filters['search']) ?>">
        <select name="group">
            <option value=""><?= $L('vip.all_groups') ?></option>
            <?php foreach ($groups as $g): ?>
                <option value="<?= View::e($g) ?>" <?= $filters['group'] === $g ? 'selected' : '' ?>><?= View::e($g) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><?= $L('common.filter') ?></button>
    </form>
    <?php if ($canManage): ?>
        <button class="btn btn-primary" data-modal="modal-vip">+ <?= $L('common.new') ?></button>
    <?php endif; ?>
</div>

<?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
<div class="live-search-results" id="search-results-vip" data-live-search-results="vip" tabindex="-1">
<div class="card" style="padding:0">
    <?php if (!$pg->items): ?>
        <div class="empty"><?= $L('vip.none') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= $L('common.name') ?></th><th><?= $L('common.steamid64') ?></th><th><?= $L('common.group') ?></th><th><?= $L('common.server') ?></th><th><?= $L('common.expires') ?></th><th><?= $L('common.status') ?></th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($pg->items as $v): ?>
                    <tr>
                        <td>
                            <a class="player-cell" <?= Steam::linkAttrs($v['steamid64']) ?>>
                                <img class="player-avatar" src="<?= View::e($avatars[(string) $v['steamid64']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                                <span class="pname"><?= View::e(Steam::displayName($v['steamid64'], $v['name'])) ?></span>
                            </a>
                        </td>
                        <td class="mono"><a <?= Steam::linkAttrs($v['steamid64']) ?>><?= View::e($v['steamid64']) ?></a></td>
                        <td><span class="badge badge-mute"><?= View::e($v['group']) ?></span></td>
                        <td class="muted"><?= View::e(($v['server_ip'] ?? '') !== '' ? $v['server_ip'] . ':' . $v['server_port'] : '—') ?></td>
                        <td class="muted">
                            <?php if ($v['is_permanent']): ?><span class="badge badge-perm"><?= $L('common.permanent') ?></span>
                            <?php elseif ($v['is_expired']): ?><span class="badge badge-expired"><?= $L('common.expired') ?></span>
                            <?php else: ?><?= View::e(date('Y-m-d H:i', (int) $v['expires'])) ?><?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $v['is_expired'] ? 'badge-expired' : 'badge-active' ?>"><?= $v['is_expired'] ? $L('common.inactive') : $L('common.active') ?></span>
                        </td>
                        <?php if ($canManage): ?>
                        <td style="white-space:nowrap">
                            <button class="btn btn-sm"
                                data-modal="modal-vip-edit"
                                data-set-steamid64="<?= View::e($v['steamid64']) ?>"
                                data-set-sid="<?= (int) $v['sid'] ?>"
                                data-set-name="<?= View::e($v['name']) ?>"
                                data-set-group="<?= View::e($v['group']) ?>"
                                data-set-permanent="<?= $v['is_permanent'] ? '1' : '0' ?>"
                                data-set-expires_at_date="<?= $v['is_permanent'] ? '' : View::e(date('Y-m-d', (int) $v['expires'])) ?>"
                                data-set-expires_at_time="<?= $v['is_permanent'] ? '' : View::e(date('H:i', (int) $v['expires'])) ?>"
                            ><?= $L('common.edit') ?></button>
                            <button class="btn btn-sm" data-modal="modal-extend" data-set-steamid64="<?= View::e($v['steamid64']) ?>" data-set-sid="<?= (int) $v['sid'] ?>"><?= $L('vip.extend') ?></button>
                            <form method="post" action="/vip/remove" style="display:inline" data-confirm="<?= $L('vip.remove_confirm') ?>">
                                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                <input type="hidden" name="steamid64" value="<?= View::e($v['steamid64']) ?>">
                                <input type="hidden" name="sid" value="<?= (int) $v['sid'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit"><?= $L('common.delete') ?></button>
                            </form>
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

<?php if ($canManage): ?>
<?php
$serverOptions = function () use ($servers) {
    if (!$servers) { echo '<option value="0">' . View::e(Lang::t('vip.default_server')) . '</option>'; return; }
    foreach ($servers as $s) {
        echo '<option value="' . (int) $s['serverId'] . '">' . View::e($s['serverIp'] . ':' . $s['port']) . '</option>';
    }
};
?>
<div class="modal-overlay" id="modal-vip">
    <div class="modal">
        <form method="post" action="/vip">
            <div class="modal-head"><h3><?= $L('vip.new_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('common.steamid64') ?></label><input type="text" name="steamid64" placeholder="7656119..." required></div>
                <div class="field"><label><?= $L('common.name') ?></label><input type="text" name="name" placeholder="VIP"></div>
                <div class="form-row">
                    <div class="field"><label><?= $L('vip.group') ?></label><?= $groupField() ?></div>
                    <div class="field"><label><?= $L('vip.duration_days') ?></label><input type="number" name="days" value="30"></div>
                </div>
                <div class="field"><label><?= $L('common.server') ?></label><select name="sid"><?php $serverOptions(); ?></select></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.add') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="modal-vip-edit">
    <div class="modal">
        <form method="post" action="/vip/update">
            <div class="modal-head"><h3><?= $L('vip.edit_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="steamid64" value="">
                <input type="hidden" name="sid" value="">
                <div class="field"><label><?= $L('common.name') ?></label><input type="text" name="name" placeholder="VIP"></div>
                <div class="field"><label><?= $L('vip.group') ?></label><?= $groupField() ?></div>
                <div class="field">
                    <label><?= $L('vip.expiry') ?></label>
                    <label class="inline-check"><input type="radio" name="expiry_mode" value="date" checked> <?= $L('vip.expires_at') ?></label>
                    <label class="inline-check"><input type="radio" name="expiry_mode" value="permanent"> <?= $L('common.permanent') ?></label>
                </div>
                <div class="field" data-expiry-date>
                    <div style="display:flex;gap:8px">
                        <input type="date" name="expires_at_date" value="" style="flex:1">
                        <input type="text" name="expires_at_time" value="" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" title="24-hour HH:MM" style="width:90px">
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.save') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('modal-vip-edit');
    if (!modal) return;
    var dateWrap = modal.querySelector('[data-expiry-date]');
    function syncMode() {
        var perm = modal.querySelector('input[name="expiry_mode"][value="permanent"]').checked;
        if (dateWrap) dateWrap.style.display = perm ? 'none' : '';
    }
    modal.querySelectorAll('input[name="expiry_mode"]').forEach(function (r) {
        r.addEventListener('change', syncMode);
    });
    document.addEventListener('click', function (event) {
            var opener = event.target.closest('[data-modal="modal-vip-edit"]');
            if (!opener) return;
            var perm = opener.getAttribute('data-set-permanent') === '1';
            modal.querySelector('input[name="expiry_mode"][value="permanent"]').checked = perm;
            modal.querySelector('input[name="expiry_mode"][value="date"]').checked = !perm;
            setTimeout(syncMode, 0);
    }, true);
    syncMode();
})();
</script>

<div class="modal-overlay" id="modal-extend">
    <div class="modal">
        <form method="post" action="/vip/extend">
            <div class="modal-head"><h3><?= $L('vip.extend_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="steamid64" value="">
                <input type="hidden" name="sid" value="">
                <div class="field"><label><?= $L('vip.days_to_add') ?></label><input type="number" name="days" value="30"></div>
                <p class="muted"><?= $L('vip.extend_note') ?></p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('vip.extend') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
