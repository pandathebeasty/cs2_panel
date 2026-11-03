<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var array $users @var array $avatars @var string $csrf */
$title = Lang::t('pu.title');
$L = fn(string $k) => View::e(Lang::t($k));
$avatars = $avatars ?? [];
?>
<div class="toolbar">
    <div class="spacer"></div>
    <button class="btn btn-primary" data-modal="modal-pu-new">+ <?= $L('common.new') ?></button>
</div>

<p class="muted" style="margin-bottom:16px">
    <?= $L('pu.intro') ?>
</p>

<div class="card" style="padding:0">
    <?php if (!$users): ?>
        <div class="empty"><?= $L('pu.none') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= $L('common.name') ?></th><th><?= $L('common.steamid64') ?></th><th><?= $L('pu.role') ?></th><th><?= $L('pu.added') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <?php $sid = (string) $u['steamid64']; ?>
                    <tr>
                        <td>
                            <a class="player-cell" <?= Steam::linkAttrs($sid) ?>>
                                <img class="player-avatar" src="<?= View::e($avatars[$sid] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                                <span class="pname"><?= View::e(Steam::displayName($sid, $u['name'])) ?></span>
                            </a>
                        </td>
                        <td class="mono"><a <?= Steam::linkAttrs($sid) ?>><?= View::e($sid) ?></a></td>
                        <td><span class="badge badge-role-<?= View::e($u['role']) ?>"><?= View::e(ucfirst($u['role'])) ?></span></td>
                        <td class="muted"><?= View::e($u['created_at']) ?></td>
                        <td style="white-space:nowrap">
                            <button class="btn btn-sm"
                                data-modal="modal-pu-edit"
                                data-set-name="<?= View::e($u['name']) ?>"
                                data-set-role="<?= View::e($u['role']) ?>"
                                onclick="document.getElementById('pu-edit-form').action=(window.CS2_BASE||'')+'/panel-users/<?= (int) $u['id'] ?>/update'"><?= $L('common.edit') ?></button>
                            <form method="post" action="/panel-users/<?= (int) $u['id'] ?>/delete" style="display:inline" data-confirm="<?= $L('pu.remove_confirm') ?>">
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

<div class="modal-overlay" id="modal-pu-new">
    <div class="modal">
        <form method="post" action="/panel-users">
            <div class="modal-head"><h3><?= $L('pu.new_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('common.steamid64') ?></label><input type="text" name="steamid64" placeholder="7656119..." required></div>
                <div class="field"><label><?= $L('pu.display_name') ?></label><input type="text" name="name" value="Moderator"></div>
                <div class="field"><label><?= $L('pu.role') ?></label>
                    <select name="role">
                        <option value="moderator"><?= $L('pu.role_moderator') ?></option>
                        <option value="owner"><?= $L('pu.role_owner') ?></option>
                    </select>
                    <p class="hint" style="margin-top:6px"><?= $L('pu.role_hint') ?></p>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.add') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit panel user modal -->
<div class="modal-overlay" id="modal-pu-edit">
    <div class="modal">
        <form method="post" action="/panel-users/0/update" id="pu-edit-form">
            <div class="modal-head"><h3><?= $L('pu.edit_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('pu.display_name') ?></label><input type="text" name="name" required></div>
                <div class="field"><label><?= $L('pu.role') ?></label>
                    <select name="role">
                        <option value="moderator"><?= $L('pu.role_moderator') ?></option>
                        <option value="owner"><?= $L('pu.role_owner') ?></option>
                    </select>
                    <p class="hint" style="margin-top:6px"><?= $L('pu.role_hint') ?></p>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.save') ?></button>
            </div>
        </form>
    </div>
</div>

