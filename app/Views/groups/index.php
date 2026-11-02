<?php
use App\Core\Lang;
use App\Core\View;
/** @var array $groups @var array $servers @var array $flagList @var array $flagGroups @var string $csrf */
$title = Lang::t('nav.groups');
$L = fn(string $k) => View::e(Lang::t($k));
$flagGroups = $flagGroups ?? [];

// All flags known to the panel (across every group) — used to detect custom
// flags that were entered manually so they still render as checkboxes.
$knownFlags = [];
foreach ($flagGroups as $flags) {
    foreach ($flags as $flag => $_l) { $knownFlags[$flag] = true; }
}

/**
 * Render the flag checkboxes: one titled section per plugin group, plus a
 * trailing "Custom" section for any flags entered manually. $checked is the
 * list of flags currently on the group being edited (empty for the New modal).
 */
$flagChecks = function (array $checked) use ($flagGroups, $knownFlags, $L): string {
    $box = function (string $flag, string $label, bool $on): string {
        $id = 'gf_' . substr(md5($flag . mt_rand()), 0, 8);
        return '<div class="check"><input type="checkbox" name="flags[]" value="' . View::e($flag) . '" id="' . $id . '" '
            . ($on ? 'checked' : '') . '><label for="' . $id . '">' . View::e($label) . '</label></div>';
    };
    $h = '';
    foreach ($flagGroups as $groupLabel => $flags) {
        $h .= '<div class="flag-group-title">' . View::e($groupLabel) . '</div><div class="form-row">';
        foreach ($flags as $flag => $label) {
            $h .= $box($flag, \App\Core\Flags::label($flag, $label), in_array($flag, $checked, true));
        }
        $h .= '</div>';
    }
    // Any flag on this group that the panel doesn't know about = custom flag.
    // Custom flags saved earlier live in panel_settings and always show up.
    $custom = \App\Core\Flags::custom();
    foreach ($checked as $f) {
        if (\App\Core\Flags::isValid($f) && !isset($knownFlags[$f]) && !in_array($f, $custom, true)) { $custom[] = $f; }
    }
    $h .= '<div class="flag-group-title">' . $L('groups.custom_flag') . '</div>';
    $h .= '<div class="form-row js-custom-flags">';
    foreach ($custom as $flag) {
        $h .= $box($flag, $flag, in_array($flag, $checked, true));
    }
    $h .= '</div>';
    // Input to add a new custom flag on the fly.
    $h .= '<div class="custom-flag-add">'
        . '<input type="text" class="js-custom-flag-input" placeholder="' . $L('groups.custom_flag_ph') . '">'
        . '<button type="button" class="btn btn-sm js-custom-flag-btn">' . $L('groups.custom_flag_add') . '</button>'
        . '</div>';
    return $h;
};
$serverChecks = function (array $checked, array $servers): string {
    if (!$servers) return '<p class="muted">' . View::e(Lang::t('servers.none')) . '</p>';
    $h = '<div class="form-row">';
    foreach ($servers as $s) {
        $c = in_array((int) $s['id'], $checked, true) ? 'checked' : '';
        $id = 'gs_' . (int) $s['id'] . '_' . mt_rand();
        $h .= '<div class="check"><input type="checkbox" name="servers[]" value="' . (int) $s['id'] . '" id="' . $id . '" ' . $c . '><label for="' . $id . '">' . View::e($s['hostname']) . '</label></div>';
    }
    return $h . '</div>';
};
?>
<div class="toolbar">
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal="modal-group-new">+ <?= $L('common.new') ?></button>
</div>

<div class="card" style="padding:0">
    <?php if (!$groups): ?>
        <div class="empty"><?= $L('groups.none') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= $L('common.name') ?></th><th><?= $L('groups.immunity') ?></th><th><?= $L('groups.flags') ?></th><th><?= $L('groups.servers') ?></th><th><?= $L('groups.admins') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                                    <?php $visibleFlags = array_values(array_filter($g['flags'], static fn($f) => \App\Core\Flags::isValid((string) $f))); ?>
                    <tr>
                        <td><span class="badge badge-role-admin" title="<?= View::e($g['name']) ?>"><?= View::e($g['label'] ?? $g['name']) ?></span></td>
                        <td><?= (int) $g['immunity'] ?></td>
                        <td><div class="tag-flags">
                            <?php foreach (array_slice($visibleFlags, 0, 6) as $f): ?><span class="tag"><?= View::e($f) ?></span><?php endforeach; ?>
                            <?php if (count($visibleFlags) > 6): ?><span class="tag">+<?= count($visibleFlags) - 6 ?></span><?php endif; ?>
                            <?php if (!$visibleFlags): ?><span class="muted">—</span><?php endif; ?>
                        </div></td>
                        <td class="muted"><?= $g['servers'] ? count($g['servers']) . ' ' . $L('groups.servers_count') : $L('common.all') ?></td>
                        <td class="muted"><?= (int) $g['admin_count'] ?></td>
                        <td style="white-space:nowrap">
                            <button class="btn btn-sm"
                                data-modal="modal-group-edit"
                                data-set-name="<?= View::e($g['name']) ?>"
                                data-set-immunity="<?= (int) $g['immunity'] ?>"
                                data-flags="<?= View::e(implode(',', $visibleFlags)) ?>"
                                data-servers="<?= View::e(implode(',', $g['servers'])) ?>"
                                onclick="document.getElementById('group-edit-form').action=(window.CS2_BASE||'')+'/groups/<?= (int) $g['id'] ?>/update'"><?= $L('common.edit') ?></button>
                            <form method="post" action="/groups/<?= (int) $g['id'] ?>/delete" style="display:inline" data-confirm="<?= $L('groups.delete_confirm') ?>">
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

<!-- New group modal -->
<div class="modal-overlay" id="modal-group-new">
    <div class="modal">
        <form method="post" action="/groups">
            <div class="modal-head"><h3><?= $L('groups.new_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="form-row">
                    <div class="field"><label><?= $L('groups.group_name') ?></label><input type="text" name="name" required></div>
                    <div class="field"><label><?= $L('groups.immunity') ?></label><input type="number" name="immunity" value="0"></div>
                </div>
                <label style="font-size:12.5px;color:var(--text-dim);font-weight:600"><?= $L('groups.flags') ?></label>
                <?= $flagChecks([]) ?>
                <label style="font-size:12.5px;color:var(--text-dim);font-weight:600;margin-top:8px;display:block"><?= $L('groups.servers_all') ?></label>
                <?= $serverChecks([], $servers) ?>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('servers.create') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit group modal -->
<div class="modal-overlay" id="modal-group-edit">
    <div class="modal">
        <form method="post" action="/groups/0/update" id="group-edit-form">
            <div class="modal-head"><h3><?= $L('groups.edit_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="form-row">
                    <div class="field"><label><?= $L('groups.group_name') ?></label><input type="text" name="name" required></div>
                    <div class="field"><label><?= $L('groups.immunity') ?></label><input type="number" name="immunity" value="0"></div>
                </div>
                <label style="font-size:12.5px;color:var(--text-dim);font-weight:600"><?= $L('groups.flags') ?></label>
                <?= $flagChecks([]) ?>
                <label style="font-size:12.5px;color:var(--text-dim);font-weight:600;margin-top:8px;display:block"><?= $L('groups.servers_all') ?></label>
                <?= $serverChecks([], $servers) ?>
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
function ccAddCustomFlag(form, flag) {
    flag = (flag || '').trim();
    if (!flag || /^@web(?:\/|$)/i.test(flag)
        || ['@vip/vip', '@admin/generic', '@admin/root'].indexOf(flag.toLowerCase()) !== -1) return;
    // Don't duplicate an existing checkbox (known or custom).
    var existing = form.querySelector('input[name="flags[]"][value="' + (window.CSS && CSS.escape ? CSS.escape(flag) : flag) + '"]');
    if (existing) { existing.checked = true; return; }
    var box = form.querySelector('.js-custom-flags');
    if (!box) return;
    var id = 'gfc_' + Math.random().toString(36).slice(2, 9);
    var wrap = document.createElement('div');
    wrap.className = 'check';
    var cb = document.createElement('input');
    cb.type = 'checkbox'; cb.name = 'flags[]'; cb.value = flag; cb.id = id; cb.checked = true;
    var lb = document.createElement('label'); lb.htmlFor = id; lb.textContent = flag;
    wrap.appendChild(cb); wrap.appendChild(lb);
    box.appendChild(wrap);
}

// Wire the "Add" custom-flag button in every modal.
document.querySelectorAll('.js-custom-flag-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var form = btn.closest('form');
        var input = form.querySelector('.js-custom-flag-input');
        ccAddCustomFlag(form, input.value);
        input.value = '';
        input.focus();
    });
});
// Enter key inside the custom-flag input adds it (without submitting the form).
document.querySelectorAll('.js-custom-flag-input').forEach(function (input) {
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); var form = input.closest('form'); ccAddCustomFlag(form, input.value); input.value = ''; }
    });
});

document.querySelectorAll('[data-modal="modal-group-edit"]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var form = document.getElementById('group-edit-form');
        var flags = (btn.dataset.flags || '').split(',').filter(Boolean);
        var servers = (btn.dataset.servers || '').split(',').filter(Boolean);
        form.querySelectorAll('input[name="flags[]"]').forEach(function (cb) { cb.checked = flags.indexOf(cb.value) !== -1; });
        // Re-add custom flags that have no built-in checkbox.
        flags.forEach(function (f) {
            if (!form.querySelector('input[name="flags[]"][value="' + (window.CSS && CSS.escape ? CSS.escape(f) : f) + '"]')) {
                ccAddCustomFlag(form, f);
            }
        });
        form.querySelectorAll('input[name="servers[]"]').forEach(function (cb) { cb.checked = servers.indexOf(cb.value) !== -1; });
    });
});
</script>
