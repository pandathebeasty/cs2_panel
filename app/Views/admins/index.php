<?php
use App\Core\Database;
use App\Core\Config;
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var array $admins @var \App\Core\Paginator $pager @var array $groups @var array $servers @var array $flagList @var array $flagGroups @var array $customFlags @var array $avatars @var string $csrf @var string $search */
$avatars = $avatars ?? [];
$flagGroups = $flagGroups ?? [];
$customFlags = $customFlags ?? [];
$servers = $servers ?? [];
$inheritedFlagsByAdmin = $inheritedFlagsByAdmin ?? [];
$search = $search ?? '';
$pg = $pager ?? null;
$liveSearchKey = 'admins';
$title = Lang::t('nav.admins');
$L = fn(string $k) => View::e(Lang::t($k));
$p = Config::get('sa_prefix', 'sa_');

// Direct flags for each admin row. A player can own several sa_admins rows
// (one per server), so every id of the collapsed row is looked up at once.
$flagsByAdmin = [];
$rowIds = [];
foreach ($admins as $a) {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($a['admin_ids'] ?? $a['id'])))));
    $rowIds[(int) $a['id']] = $ids ?: [(int) $a['id']];
    $flagsByAdmin[(int) $a['id']] = [];
}
$allIds = array_values(array_unique(array_merge(...array_values($rowIds ?: [[]]))));
$flagByDbId = [];
if ($allIds) {
    $ph = implode(',', array_fill(0, count($allIds), '?'));
    try {
        foreach (Database::all("SELECT admin_id, flag FROM {$p}admins_flags WHERE admin_id IN ({$ph})", $allIds) as $row) {
            $flagByDbId[(int) $row['admin_id']][] = $row['flag'];
        }
    } catch (\Throwable $e) {
        // Legacy installs without sa_admins_flags: fall back to the text column.
    }
}
foreach ($admins as $a) {
    $key = (int) $a['id'];
    $collected = [];
    foreach ($rowIds[$key] as $dbId) {
        foreach ($flagByDbId[$dbId] ?? [] as $f) { $collected[$f] = true; }
    }
    // Legacy comma/space separated sa_admins.flags column.
    foreach (preg_split('/[\s,]+/', (string) ($a['legacy_flags'] ?? '')) ?: [] as $f) {
        if ($f !== '') { $collected[$f] = true; }
    }
    foreach ($inheritedFlagsByAdmin[$key] ?? [] as $f) {
        $collected[$f] = true;
    }
    foreach (array_keys($collected) as $f) {
        if (str_starts_with($f, '#') || \App\Core\Flags::isPanelFlag($f) || \App\Core\Flags::isRetiredFlag($f)) { unset($collected[$f]); }
    }
    $flagsByAdmin[$key] = array_keys($collected);
}

$knownFlags = [];
foreach ($flagGroups as $flags) {
    foreach ($flags as $flag => $_l) { $knownFlags[$flag] = true; }
}

$renderFlagChecks = function (array $checked) use ($flagGroups, $customFlags, $knownFlags, $L): string {
    $box = function (string $flag, string $label, bool $on): string {
        $id = 'f_' . substr(md5($flag . mt_rand()), 0, 8);
        return '<div class="check"><input type="checkbox" name="flags[]" value="' . View::e($flag) . '" id="' . $id . '" '
            . ($on ? 'checked' : '') . '><label for="' . $id . '">' . View::e($label) . '</label></div>';
    };
    $html = '';
    foreach ($flagGroups as $groupLabel => $flags) {
        $html .= '<div class="flag-group-title">' . View::e($groupLabel) . '</div><div class="form-row">';
        foreach ($flags as $flag => $label) {
            $html .= $box($flag, \App\Core\Flags::label($flag, $label), in_array($flag, $checked, true));
        }
        $html .= '</div>';
    }
    // Custom flags persisted in the panel settings + anything still unknown.
    $custom = $customFlags;
    foreach ($checked as $f) {
        if (\App\Core\Flags::isValid($f) && !isset($knownFlags[$f]) && !in_array($f, $custom, true)) { $custom[] = $f; }
    }
    $html .= '<div class="flag-group-title">' . $L('groups.custom_flag') . '</div><div class="form-row js-custom-flags">';
    foreach ($custom as $flag) {
        $html .= $box($flag, $flag, in_array($flag, $checked, true));
    }
    $html .= '</div><div class="custom-flag-add">'
        . '<input type="text" class="js-custom-flag-input" placeholder="' . $L('groups.custom_flag_ph') . '">'
        . '<button type="button" class="btn btn-sm js-custom-flag-btn">' . $L('groups.custom_flag_add') . '</button>'
        . '</div>';
    return $html;
};
?>
<div class="toolbar">
    <form method="get" data-live-search="admins">
        <input type="text" name="search" placeholder="<?= $L('admins.search_ph') ?>" value="<?= View::e($search) ?>">
        <button class="btn" type="submit"><?= $L('common.search') ?></button>
    </form>
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal="modal-admin-new">+ <?= $L('common.new') ?></button>
</div>

<?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
<div class="live-search-results" id="search-results-admins" data-live-search-results="admins" tabindex="-1">
<div class="card" style="padding:0">
    <?php if (!$admins): ?>
        <div class="empty"><?= $L('admins.none') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= $L('common.name') ?></th><th><?= $L('common.steamid') ?></th><th><?= $L('common.group') ?></th><th><?= $L('groups.immunity') ?></th><th><?= $L('groups.flags') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($admins as $a): $af = $flagsByAdmin[$a['id']]; ?>
                    <tr>
                        <td>
                            <?php $sid = (string) $a['player_steamid']; $valid = Steam::isValidSteamId64($sid); ?>
                            <?php if ($valid): ?>
                                <a class="player-cell" <?= Steam::linkAttrs($sid) ?>>
                                    <img class="player-avatar" src="<?= View::e($avatars[$sid] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                                    <span class="pname"><?= View::e(Steam::displayName($sid, $a['player_name'])) ?></span>
                                </a>
                            <?php else: ?>
                                <span class="pname"><?= View::e($a['player_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mono"><?php if ($valid): ?><a <?= Steam::linkAttrs($sid) ?>><?= View::e($sid) ?></a><?php else: ?><?= View::e($sid) ?><?php endif; ?></td>
                        <td><?php $gnames = array_filter(array_map('trim', explode(',', (string) ($a['group_name'] ?? '')))); ?>
                            <?php if ($gnames): ?><div class="tag-flags"><?php foreach ($gnames as $gn): ?><span class="badge badge-role-admin"><?= View::e($gn) ?></span><?php endforeach; ?></div><?php else: ?><span class="muted">—</span><?php endif; ?>
                        </td>
                        <td><?= (int) $a['immunity'] ?></td>
                        <td><div class="tag-flags">
                            <?php foreach (array_slice($af, 0, 6) as $f): ?><span class="tag"><?= View::e($f) ?></span><?php endforeach; ?>
                            <?php if (count($af) > 6): ?><span class="tag">+<?= count($af) - 6 ?></span><?php endif; ?>
                            <?php if (!$af): ?><span class="muted">—</span><?php endif; ?>
                        </div></td>
                        <td style="white-space:nowrap">
                            <button class="btn btn-sm"
                                data-modal="modal-admin-edit"
                                data-set-player_name="<?= View::e($a['player_name']) ?>"
                                data-set-immunity="<?= (int) $a['immunity'] ?>"
                                data-set-group_id="<?= (int) ($a['group_id'] ?? 0) ?>"
                                data-set-server_id="<?= (int) (explode(',', (string) ($a['server_ids'] ?? ''))[0] ?? 0) ?>"
                                data-admin-id="<?= (int) $a['id'] ?>"
                                data-flags="<?= View::e(implode(',', $af)) ?>"
                                onclick="document.getElementById('edit-form').action=(window.CS2_BASE||'')+'/admins/<?= (int) $a['id'] ?>/update'"><?= $L('common.edit') ?></button>
                            <form method="post" action="/admins/<?= (int) $a['id'] ?>/delete" style="display:inline" data-confirm="<?= $L('admins.delete_confirm') ?>">
                                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                <button class="btn btn-sm btn-danger" type="submit"><?= $L('common.delete') ?></button>
                            </form>
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

<!-- New admin modal -->
<div class="modal-overlay" id="modal-admin-new">
    <div class="modal">
        <form method="post" action="/admins">
            <div class="modal-head"><h3><?= $L('admins.new_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('common.name') ?></label><input type="text" name="player_name" required></div>
                <div class="field"><label><?= $L('common.steamid64') ?></label><input type="text" name="player_steamid" required></div>
                <div class="form-row">
                    <div class="field"><label><?= $L('groups.immunity') ?></label><input type="number" name="immunity" required></div>
                    <div class="field"><label><?= $L('common.group') ?></label>
                        <select name="group_id" class="js-group-select" required><option value=""><?= $L('admins.no_group') ?></option>
                            <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>" data-flags="<?= View::e(implode(',', $groupFlags[(int) $g['id']] ?? [])) ?>"><?= View::e($g['label']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field"><label><?= $L('common.server') ?></label>
                    <select name="server_id"><option value=""><?= $L('common.all_servers') ?></option>
                        <?php foreach ($servers as $sv): ?><option value="<?= (int) $sv['id'] ?>"><?= View::e($sv['hostname'] ?: $sv['address']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <label style="font-size:12.5px;color:var(--text-dim);font-weight:600"><?= $L('groups.flags') ?></label>
                <?= $renderFlagChecks([]) ?>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.add') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit admin modal -->
<div class="modal-overlay" id="modal-admin-edit">
    <div class="modal">
        <form method="post" action="/admins/0/update" id="edit-form">
            <div class="modal-head"><h3><?= $L('admins.edit_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('common.name') ?></label><input type="text" name="player_name" required></div>
                <div class="form-row">
                    <div class="field"><label><?= $L('groups.immunity') ?></label><input type="number" name="immunity" value="0" required></div>
                    <div class="field"><label><?= $L('common.group') ?></label>
                        <select name="group_id" class="js-group-select" required><option value=""><?= $L('admins.no_group') ?></option>
                            <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>" data-flags="<?= View::e(implode(',', $groupFlags[(int) $g['id']] ?? [])) ?>"><?= View::e($g['label']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field"><label><?= $L('common.server') ?></label>
                    <select name="server_id"><option value=""><?= $L('common.all_servers') ?></option>
                        <?php foreach ($servers as $sv): ?><option value="<?= (int) $sv['id'] ?>"><?= View::e($sv['hostname'] ?: $sv['address']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <label style="font-size:12.5px;color:var(--text-dim);font-weight:600"><?= $L('groups.flags') ?></label>
                <div id="edit-flags"><?= $renderFlagChecks([]) ?></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.save') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
// Add a custom-flag checkbox (checked) into the given form's custom section.
function acAddCustomFlag(form, flag) {
    flag = (flag || '').trim();
    if (!flag || flag[0] === '#' || /^@web(?:\/|$)/i.test(flag)
        || ['@vip/vip', '@admin/generic', '@admin/root'].indexOf(flag.toLowerCase()) !== -1) return;
    var esc = (window.CSS && CSS.escape) ? CSS.escape(flag) : flag;
    var existing = form.querySelector('input[name="flags[]"][value="' + esc + '"]');
    if (existing) { existing.checked = true; return; }
    var box = form.querySelector('.js-custom-flags');
    if (!box) return;
    var id = 'fc_' + Math.random().toString(36).slice(2, 9);
    var wrap = document.createElement('div'); wrap.className = 'check';
    var cb = document.createElement('input'); cb.type = 'checkbox'; cb.name = 'flags[]'; cb.value = flag; cb.id = id; cb.checked = true;
    var lb = document.createElement('label'); lb.htmlFor = id; lb.textContent = flag;
    wrap.appendChild(cb); wrap.appendChild(lb); box.appendChild(wrap);
}
document.querySelectorAll('.js-custom-flag-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var form = btn.closest('form');
        var input = form.querySelector('.js-custom-flag-input');
        acAddCustomFlag(form, input.value); input.value = ''; input.focus();
    });
});
document.querySelectorAll('.js-custom-flag-input').forEach(function (input) {
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); acAddCustomFlag(input.closest('form'), input.value); input.value = ''; }
    });
});

// Check the correct flags when the edit modal opens.
document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-modal="modal-admin-edit"]');
        if (!btn) return;
        var flags = (btn.dataset.flags || '').split(',').filter(Boolean);
        var form = document.getElementById('edit-form');
        form.dataset.directFlags = JSON.stringify(flags);
        form.querySelectorAll('input[name="flags[]"]').forEach(function (cb) {
            cb.checked = flags.indexOf(cb.value) !== -1;
        });
        flags.forEach(function (f) {
            var esc = (window.CSS && CSS.escape) ? CSS.escape(f) : f;
            if (!form.querySelector('input[name="flags[]"][value="' + esc + '"]')) { acAddCustomFlag(form, f); }
        });
        var gsel = form.querySelector('.js-group-select');
        if (gsel) {
            gsel.value = btn.dataset.setGroup_id || '';
            gsel.dataset.appliedFlags = '[]';
            acApplyGroupFlags(gsel);
        }
}, true);

function acApplyGroupFlags(select) {
    var form = select.closest('form');
    if (!form) return;
    var direct = new Set(JSON.parse(form.dataset.directFlags || '[]'));
    var previous = [];
    try { previous = JSON.parse(select.dataset.appliedFlags || '[]'); } catch (_) {}
    previous.forEach(function (flag) {
        if (direct.has(flag)) return;
        var old = Array.from(form.querySelectorAll('input[name="flags[]"]')).find(function (cb) { return cb.value === flag; });
        if (old) old.checked = false;
    });

    var opt = select.options[select.selectedIndex];
    var flags = (opt && opt.dataset.flags ? opt.dataset.flags : '').split(',').filter(Boolean);
    flags.forEach(function (flag) {
        if (!form.querySelector('input[name="flags[]"][value="' + ((window.CSS && CSS.escape) ? CSS.escape(flag) : flag) + '"]')) {
            acAddCustomFlag(form, flag);
        }
        var checkbox = Array.from(form.querySelectorAll('input[name="flags[]"]')).find(function (cb) { return cb.value === flag; });
        if (checkbox) checkbox.checked = true;
    });
    select.dataset.appliedFlags = JSON.stringify(flags);
}
document.querySelectorAll('.js-group-select').forEach(function (sel) {
    sel.addEventListener('change', function () { acApplyGroupFlags(sel); });
    if (sel.form && sel.form.id !== 'edit-form') acApplyGroupFlags(sel);
});
</script>
