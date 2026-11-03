<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
use App\Models\ContestModel;
/** @var array $contest @var array $winners @var array $prizes @var bool $canManage @var bool $isRoot @var array $vipGroups @var string $csrf */
$title = Lang::t('nav.contests') . ' — ' . $contest['title'];
$L = fn(string $k) => View::e(Lang::t($k));
$canManage = $canManage ?? false;
$vipGroups = $vipGroups ?? [];
$active = $contest['status'] === 'active';
$isPreview = $isPreview ?? false; // active contest => read-only standings
$scheduled = ($contest['status'] ?? '') === 'scheduled';
$statusLabelKey = $scheduled ? 'contests.status_scheduled' : ($active ? 'contests.status_active' : 'contests.status_finished');
$statusBadgeClass = $scheduled ? 'badge-warn' : ($active ? 'badge-active' : 'badge-mute');
$days = (int) $contest['vip_days'];

$vipGroupsJson = json_encode(array_values($vipGroups));

// Is this a play-time contest? The winners' stored "points" are then minutes.
$isPlaytime = ($contest['metric'] ?? 'rank') === 'playtime';
$hm = function ($minutes): string {
    $m = (int) $minutes;
    $h = intdiv($m, 60);
    return $h > 0 ? ($h . 'h ' . ($m % 60) . 'm') : ($m . 'm');
};

// Steam avatars for the winners (batch), like the dashboard/bans tables.
$winnerAvatars = $winners ? Steam::avatars(array_column($winners, 'steamid')) : [];
// Preview rows (active contest) are not stored, so nothing is grantable yet.
$hasUngrantedWinners = !$isPreview && (bool) array_filter($winners, static fn(array $w): bool => ContestModel::hasPendingPrize($contest, $w));
?>
<div class="toolbar">
    <a class="btn btn-ghost" href="/contests">&larr; <?= $L('contests.back') ?></a>
    <?php if ($canManage): ?>
    <div class="toolbar-actions">
        <button class="btn" data-modal="modal-contest-edit"><?= $L('common.edit') ?></button>
        <form method="post" action="/contests/<?= (int) $contest['id'] ?>/resend" style="display:inline">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <button class="btn" type="submit"><?= $L('contests.resend') ?></button>
        </form>
        <?php if ($active): ?>
            <form method="post" action="/contests/<?= (int) $contest['id'] ?>/redraw" style="display:inline">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <button class="btn" type="submit" title="<?= $L('contests.redraw_hint') ?>"><?= $L('contests.redraw') ?></button>
            </form>
            <form method="post" action="/contests/<?= (int) $contest['id'] ?>/process" style="display:inline" data-confirm="<?= $L('contests.process_confirm') ?>">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <button class="btn btn-primary" type="submit"><?= $L('contests.process') ?></button>
            </form>
        <?php endif; ?>
        <?php if ($hasUngrantedWinners): ?>
            <form method="post" action="/contests/<?= (int) $contest['id'] ?>/grant-all" style="display:inline" data-confirm="<?= $L('contests.grant_all_confirm') ?>">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <button class="btn" type="submit"><?= $L('contests.grant_all') ?></button>
            </form>
        <?php endif; ?>
        <form method="post" action="/contests/<?= (int) $contest['id'] ?>/delete" style="display:inline" data-confirm="<?= $L('contests.delete_confirm') ?>">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <button class="btn btn-danger" type="submit"><?= $L('common.delete') ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom:18px">
    <div class="cc-detail-head">
        <h2 style="margin:0"><?= View::e($contest['title']) ?></h2>
        <span class="badge <?= $statusBadgeClass ?>"><?= $L($statusLabelKey) ?></span>
    </div>
    <div class="detail-grid">
        <div><span class="cc-label"><?= $L('contests.mode') ?></span><div><?= $contest['mode'] === 'auto' ? $L('contests.mode_auto') : $L('contests.mode_manual') ?></div></div>
        <div><span class="cc-label"><?= $L('contests.metric') ?></span><div><?= ($contest['metric'] ?? 'rank') === 'playtime' ? $L('contests.metric_playtime') : $L('contests.metric_rank') ?></div></div>
        <div><span class="cc-label"><?= $L('contests.max_winners') ?></span><div><?= (int) $contest['max_winners'] ?></div></div>
        <?php if (!empty($contest['start_at'])): ?>
            <div><span class="cc-label"><?= $L('contests.start_at') ?></span><div><?= View::e(date('Y-m-d H:i', strtotime((string) $contest['start_at']))) ?></div></div>
        <?php endif; ?>
        <div><span class="cc-label"><?= $L('contests.ends_at') ?></span><div><?= View::e(date('Y-m-d H:i', strtotime((string) $contest['ends_at']))) ?></div></div>
        <div style="grid-column:1/-1">
            <span class="cc-label"><?= $L('contests.prizes_per_place') ?></span>
            <div class="cc-prize-list">
                <?php for ($place = 1; $place <= (int) $contest['max_winners']; $place++):
                    $pz = App\Models\ContestModel::prizeFor($contest, $place); ?>
                    <div class="cc-prize-line">
                        <span class="cw-pos">#<?= $place ?></span>
                        <span class="badge badge-mute"><?= View::e(ContestModel::prizeLabel($pz)) ?></span>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php if (!empty($contest['awarded_at'])): ?>
            <div><span class="cc-label"><?= $L('contests.awarded_at') ?></span><div><?= View::e(date('Y-m-d H:i', strtotime((string) $contest['awarded_at']))) ?></div></div>
        <?php endif; ?>
        <div><span class="cc-label"><?= $L('contests.reset_stats') ?></span><div><?= !empty($contest['reset_stats']) ? $L('common.yes') : $L('common.no') ?></div></div>
    </div>
</div>

<div class="card" style="padding:0">
    <div class="card-title" style="padding:14px 16px 0"><?= ((string) ($contest['status'] ?? '') === 'active') ? $L('contests.standings') : $L('contests.winners') ?></div>
    <?php if (!$winners): ?>
        <div class="empty"><?= $L('contests.no_winners_yet') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>#</th>
                    <th><?= $L('dash.player') ?></th>
                    <th><?= $L('common.steamid64') ?></th>
                    <th><?= $isPlaytime ? $L('contests.metric_playtime') : $L('dash.points') ?></th>
                    <th><?= $L('contests.prize') ?></th>
                    <th><?= $L('common.status') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($winners as $w): ?>
                    <?php
                    $wsid = (string) $w['steamid'];
                    $winnerPrize = ContestModel::winnerPrize($contest, $w);
                    ?>
                    <tr>
                        <td class="cw-pos">#<?= (int) $w['position'] ?></td>
                        <td>
                            <a <?= Steam::linkAttrs($wsid) ?> class="player-cell">
                                <img class="player-avatar" src="<?= View::e($winnerAvatars[$wsid] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                                <span class="pname"><?= View::e($w['name'] ?: '—') ?></span>
                            </a>
                        </td>
                        <td class="mono muted"><?= View::e($w['steamid']) ?></td>
                        <td><?= $isPlaytime ? View::e($hm($w['points'])) : View::e(number_format((int) $w['points'])) ?></td>
                        <td class="contest-prize-value"><span class="badge badge-mute"><?= View::e(ContestModel::prizeLabel($winnerPrize)) ?></span></td>
                        <td>
                            <?php if ($isPreview): ?><span class="muted">—</span><?php else: ?>
                                <div class="contest-prize-status">
                                    <?php if ($winnerPrize['group'] !== ''): ?>
                                        <span class="badge <?= !empty($w['vip_granted']) ? 'badge-active' : 'badge-expired' ?>"><?= $L(!empty($w['vip_granted']) ? 'contests.vip_granted' : 'contests.vip_pending') ?></span>
                                    <?php endif; ?>
                                    <?php if ($winnerPrize['credits'] > 0): ?>
                                        <span class="badge <?= !empty($w['credits_granted']) ? 'badge-active' : 'badge-expired' ?>"><?= $L(!empty($w['credits_granted']) ? 'contests.credits_granted' : 'contests.credits_pending') ?></span>
                                    <?php endif; ?>
                                    <?php if ($canManage && ContestModel::hasPendingPrize($contest, $w)): ?>
                                        <form method="post" action="/contests/<?= (int) $contest['id'] ?>/grant/<?= (int) $w['id'] ?>">
                                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                            <button type="submit" class="btn btn-sm btn-primary"><?= $L('contests.grant') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<?php
    $groupSelect = function (string $selected) use ($vipGroups): string {
        if (!$vipGroups) {
            return '<input type="text" name="__g" value="' . View::e($selected) . '" placeholder="VIP">';
        }
        $h = '';
        foreach ($vipGroups as $g) {
            $h .= '<option value="' . View::e($g) . '"' . ($g === $selected ? ' selected' : '') . '>' . View::e($g) . '</option>';
        }
        return $h;
    };
?>
<div class="modal-overlay" id="modal-contest-edit">
    <div class="modal">
        <form method="post" action="/contests/<?= (int) $contest['id'] ?>/update">
            <div class="modal-head"><h3><?= $L('contests.edit_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('contests.title') ?></label><input type="text" name="title" value="<?= View::e($contest['title']) ?>" required></div>
                <div class="field"><label><?= $L('contests.max_winners') ?></label><input type="number" name="max_winners" id="cce-max" value="<?= (int) $contest['max_winners'] ?>" min="1" max="50"></div>
                <div class="field"><label><?= $L('contests.start_at') ?></label><div style="display:flex;gap:8px"><input type="date" name="start_at_date" value="<?= !empty($contest['start_at']) ? View::e(date('Y-m-d', strtotime((string) $contest['start_at']))) : '' ?>" style="flex:1"><input type="text" name="start_at_time" value="<?= !empty($contest['start_at']) ? View::e(date('H:i', strtotime((string) $contest['start_at']))) : '' ?>" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" style="width:90px" title="24-hour HH:MM"></div></div>
                <div class="field"><label><?= $L('contests.ends_at') ?></label><div style="display:flex;gap:8px"><input type="date" name="ends_at_date" value="<?= View::e(date('Y-m-d', strtotime((string) $contest['ends_at']))) ?>" required style="flex:1"><input type="text" name="ends_at_time" value="<?= View::e(date('H:i', strtotime((string) $contest['ends_at']))) ?>" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" style="width:90px" required title="24-hour HH:MM"></div></div>
                <div class="field">
                    <label><?= $L('contests.prizes_per_place') ?></label>
                    <p class="hint" style="margin-top:0"><?= $L('contests.prizes_hint') ?></p>
                    <div class="cc-prize-head">
                        <span class="cc-place"></span>
                        <span class="cc-prize-group"><?= $L('contests.prize_group') ?></span>
                        <span class="cc-prize-days-h"><?= $L('contests.prize_days') ?>
                            <span class="hint-inline" title="<?= $L('contests.prize_days_tip') ?>">?</span>
                        </span>
                        <?php if (\App\Models\StoreCreditsModel::enabled()): ?>
                        <span class="cc-prize-days-h"><?= $L('contests.prize_credits') ?></span>
                        <?php endif; ?>
                    </div>
                    <div id="cce-prizes" class="cc-prizes"></div>
                </div>
                <?php $cMetric = (string) ($contest['metric'] ?? 'rank'); ?>
                <div class="field">
                    <label><?= $L('contests.metric') ?></label>
                    <label class="inline-check"><input type="radio" name="metric" value="rank" <?= $cMetric !== 'playtime' ? 'checked' : '' ?>> <?= $L('contests.metric_rank') ?></label>
                    <label class="inline-check"><input type="radio" name="metric" value="playtime" <?= $cMetric === 'playtime' ? 'checked' : '' ?>> <?= $L('contests.metric_playtime') ?></label>
                </div>
                <?php $cPtSource = (string) ($contest['playtime_source'] ?? 'all'); ?>
                <div class="field js-pt-source-edit" style="<?= $cMetric === 'playtime' ? '' : 'display:none' ?>">
                    <label><?= $L('contests.playtime_source') ?></label>
                    <label class="inline-check"><input type="radio" name="playtime_source" value="all" <?= $cPtSource !== 'ct_t' ? 'checked' : '' ?>> <?= $L('contests.pt_source_all') ?></label>
                    <label class="inline-check"><input type="radio" name="playtime_source" value="ct_t" <?= $cPtSource === 'ct_t' ? 'checked' : '' ?>> <?= $L('contests.pt_source_ct_t') ?></label>
                    <p class="hint"><?= $L('contests.pt_source_hint') ?></p>
                </div>
                <div class="field">
                    <label><?= $L('contests.mode') ?></label>
                    <label class="inline-check"><input type="radio" name="mode" value="manual" <?= $contest['mode'] !== 'auto' ? 'checked' : '' ?>> <?= $L('contests.mode_manual') ?></label>
                    <label class="inline-check"><input type="radio" name="mode" value="auto" <?= $contest['mode'] === 'auto' ? 'checked' : '' ?>> <?= $L('contests.mode_auto') ?></label>
                </div>
                <div class="field">
                    <label class="inline-check"><input type="checkbox" name="reset_at_start" value="1" <?= !empty($contest['reset_at_start']) ? 'checked' : '' ?>> <?= $L('contests.reset_at_start') ?></label>
                </div>
                <div class="field">
                    <label class="inline-check"><input type="checkbox" name="reset_stats" value="1" <?= !empty($contest['reset_stats']) ? 'checked' : '' ?>> <?= $L('contests.reset_stats') ?></label>
                </div>
                <div class="field">
                    <label class="inline-check"><input type="checkbox" name="extend_expiring" value="1" <?= !empty($contest['extend_expiring']) ? 'checked' : '' ?>> <?= $L('contests.extend_expiring') ?></label>
                    <p class="hint"><?= $L('contests.extend_expiring_hint') ?></p>
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
    var groups = <?= $vipGroupsJson ?: '[]' ?>;
    var existing = <?= json_encode($prizes ?: (object) []) ?>;
    var defGroup = <?= json_encode((string) $contest['vip_group']) ?>;
    var defDays = <?= (int) $days ?>;
    var defCredits = <?= (int) ($contest['credit_amount'] ?? 0) ?>;
    var creditsOn = <?= \App\Models\StoreCreditsModel::enabled() ? 'true' : 'false' ?>;
    var wrap = document.getElementById('cce-prizes');
    var maxInput = document.getElementById('cce-max');
    var daysLabel = <?= json_encode(Lang::t('contests.prize_days_tip')) ?>;
    var daysUnit = <?= json_encode(Lang::t('contests.days_unit')) ?>;
    if (!wrap || !maxInput) return;

    var noneLabel = <?= json_encode(Lang::t('contests.prize_no_vip')) ?>;
    function groupControl(pos, selected) {
        if (groups.length) {
            // Empty "no VIP" option lets a place grant credits only.
            var noneSel = (!selected || selected === '') ? ' selected' : '';
            var opts = '<option value=""' + noneSel + '>' + noneLabel + '</option>' + groups.map(function (g) {
                var sel = (g === selected) ? ' selected' : '';
                return '<option value="' + g.replace(/"/g, '&quot;') + '"' + sel + '>' + g + '</option>';
            }).join('');
            return '<select name="prize_group[' + pos + ']">' + opts + '</select>';
        }
        return '<input type="text" name="prize_group[' + pos + ']" value="' + (selected || '') + '" placeholder="VIP">';
    }

    function render() {
        var n = Math.max(1, Math.min(50, parseInt(maxInput.value, 10) || 1));
        var html = '';
        for (var i = 1; i <= n; i++) {
            var p = existing[i] || existing[String(i)] || null;
            var g = p ? p.group : defGroup;
            var d = p ? p.days : defDays;
            var c = p && (p.credits != null) ? p.credits : defCredits;
            html += '<div class="cc-prize-row" data-pos="' + i + '">'
                + '<span class="cc-place">#' + i + '</span>'
                + '<div class="cc-prize-group">' + groupControl(i, g) + '</div>'
                + '<div class="cc-days-wrap"><input class="cc-prize-days" type="number" name="prize_days[' + i + ']" value="' + (d || 0) + '" min="0" title="' + daysLabel + '"></div>'
                + (creditsOn ? '<div class="cc-days-wrap"><input class="cc-prize-days" type="number" name="prize_credits[' + i + ']" value="' + (c || 0) + '" min="0"></div>' : '')
                + '</div>';
        }
        wrap.innerHTML = html;
    }
    maxInput.addEventListener('input', render);
    render();

    // Show/hide playtime_source in edit modal when metric = playtime.
    var ptEdit = document.querySelector('.js-pt-source-edit');
    if (ptEdit) {
        document.querySelectorAll('#modal-contest-edit input[name="metric"]').forEach(function (r) {
            r.addEventListener('change', function () {
                ptEdit.style.display = r.value === 'playtime' && r.checked ? '' : 'none';
            });
        });
    }
})();
</script>
<?php endif; ?>
