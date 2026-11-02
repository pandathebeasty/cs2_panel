<?php
use App\Core\Lang;
use App\Core\View;
use App\Models\ContestModel;
/** @var array $contests @var array $vipGroups @var bool $zenithOn @var bool $webhookSet @var int $retention @var bool $canManage @var string $csrf */
$title = Lang::t('nav.contests');
$L = fn(string $k) => View::e(Lang::t($k));
$canManage = $canManage ?? false;
$vipGroups = $vipGroups ?? [];

$statusBadge = function (string $status): string {
    if ($status === 'active') {
        return '<span class="badge badge-active">' . View::e(Lang::t('contests.status_active')) . '</span>';
    }
    if ($status === 'scheduled') {
        return '<span class="badge badge-warn">' . View::e(Lang::t('contests.status_scheduled')) . '</span>';
    }
    return '<span class="badge badge-mute">' . View::e(Lang::t('contests.status_finished')) . '</span>';
};
$modeLabel = fn(string $m): string => $m === 'auto'
    ? View::e(Lang::t('contests.mode_auto'))
    : View::e(Lang::t('contests.mode_manual'));

// Options for a prize-group <select>, or a text input fallback.
$vipGroupsJson = json_encode(array_values($vipGroups));
?>
<div class="toolbar">
    <div class="muted" style="margin-right:auto"><?= $canManage ? $L('contests.intro') : '' ?></div>
    <?php if ($canManage): ?>
        <button class="btn btn-primary" data-modal="modal-contest">+ <?= $L('contests.new') ?></button>
    <?php endif; ?>
</div>

<?php if (!$zenithOn): ?>
    <div class="card"><div class="empty"><?= $L('contests.need_zenith') ?></div></div>
<?php endif; ?>
<?php if ($canManage && !$webhookSet): ?>
    <div class="card" style="border-left:3px solid var(--accent,#e01e37)">
        <div style="padding:12px 16px" class="muted"><?= $L('contests.no_webhook') ?>
            <a href="/settings#contests"><?= $L('contests.configure') ?></a>
        </div>
    </div>
<?php endif; ?>

<?php
// Renders a single contest card. Shared by all three status sections below.
$renderContestCard = function (array $c) use ($L, $statusBadge, $modeLabel, $canManage, $csrf): void {
    $winners = $c['winners'] ?? [];
    $ccMax = max(1, (int) $c['max_winners']);
    // Play-time contests store minutes as the winners' "points" — show "Xh Ym".
    $ccIsPlaytime = ($c['metric'] ?? 'rank') === 'playtime';
    $ccHasUngranted = $c['status'] === 'finished'
        && (bool) array_filter($winners, static fn(array $w): bool => ContestModel::hasPendingPrize($c, $w));
    $ccHm = function ($minutes): string {
        $m = (int) $minutes;
        $h = intdiv($m, 60);
        return $h > 0 ? ($h . 'h ' . ($m % 60) . 'm') : ($m . 'm');
    };
    ?>
    <div class="contest-card">
        <div class="cc-head">
            <a class="cc-title" href="/contests/<?= (int) $c['id'] ?>"><?= View::e($c['title']) ?></a>
            <?= $statusBadge((string) $c['status']) ?>
        </div>
        <div class="cc-meta">
            <span class="cc-chip"><?= $modeLabel((string) $c['mode']) ?></span>
            <?php if (!empty($c['reset_stats'])): ?><span class="cc-chip cc-chip-warn"><?= $L('contests.resets_stats') ?></span><?php endif; ?>
        </div>
        <div class="cc-prizes-list">
            <?php for ($ccPlace = 1; $ccPlace <= $ccMax; $ccPlace++):
                $ccPz = App\Models\ContestModel::prizeFor($c, $ccPlace); ?>
                <span class="cc-prize-chip"><span class="cw-pos">#<?= $ccPlace ?></span><span class="cc-chip cc-chip-prize"><?= View::e(ContestModel::prizeLabel($ccPz)) ?></span></span>
            <?php endfor; ?>
        </div>
        <?php if (!empty($c['start_at'])): ?>
            <div class="cc-row"><span class="cc-label"><?= $L('contests.start_at') ?></span><span><?= View::e(date('Y-m-d H:i', strtotime((string) $c['start_at']))) ?></span></div>
        <?php endif; ?>
        <div class="cc-row"><span class="cc-label"><?= $L('contests.ends_at') ?></span><span><?= View::e(date('Y-m-d H:i', strtotime((string) $c['ends_at']))) ?></span></div>
        <div class="cc-row"><span class="cc-label"><?= $L('contests.winners') ?></span><span><?= count($winners) ?> / <?= (int) $c['max_winners'] ?></span></div>

        <?php if ($winners): ?>
            <div class="contest-winners cc-winners">
            <?php foreach (array_slice($winners, 0, 3) as $w): ?>
                <?php $winnerPrize = ContestModel::winnerPrize($c, $w); ?>
                <div class="cw-row">
                    <span class="cw-pos">#<?= (int) $w['position'] ?></span>
                    <span class="pname"><?= View::e($w['name'] ?: '—') ?></span>
                    <span class="muted"><?= $ccIsPlaytime ? View::e($ccHm($w['points'])) : (View::e(number_format((int) $w['points'])) . ' ' . $L('dash.points')) ?></span>
                    <?php if ($c['status'] === 'finished'): ?>
                        <?php if ($winnerPrize['group'] !== ''): ?>
                            <span class="badge <?= !empty($w['vip_granted']) ? 'badge-active' : 'badge-expired' ?>"><?= $L(!empty($w['vip_granted']) ? 'contests.vip_granted' : 'contests.vip_pending') ?></span>
                        <?php endif; ?>
                        <?php if ($winnerPrize['credits'] > 0): ?>
                            <span class="badge <?= !empty($w['credits_granted']) ? 'badge-active' : 'badge-expired' ?>"><?= $L(!empty($w['credits_granted']) ? 'contests.credits_granted' : 'contests.credits_pending') ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (count($winners) > 3): ?><a class="muted cc-more" href="/contests/<?= (int) $c['id'] ?>">+<?= count($winners) - 3 ?> <?= $L('contests.more') ?></a><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="cc-actions">
            <a class="btn btn-sm" href="/contests/<?= (int) $c['id'] ?>"><?= $L('contests.details') ?></a>
            <?php if ($canManage): ?>
                <form method="post" action="/contests/<?= (int) $c['id'] ?>/resend" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <button class="btn btn-sm" type="submit"><?= $L('contests.resend') ?></button>
                </form>
            <?php endif; ?>
            <?php if ($canManage && $c['status'] === 'active'): ?>
                <form method="post" action="/contests/<?= (int) $c['id'] ?>/process" style="display:inline" data-confirm="<?= $L('contests.process_confirm') ?>">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <button class="btn btn-sm btn-primary" type="submit"><?= $L('contests.process') ?></button>
                </form>
            <?php endif; ?>
            <?php if ($canManage && $ccHasUngranted): ?>
                <form method="post" action="/contests/<?= (int) $c['id'] ?>/grant-all" style="display:inline" data-confirm="<?= $L('contests.grant_all_confirm') ?>">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <button class="btn btn-sm btn-primary" type="submit"><?= $L('contests.grant_all') ?></button>
                </form>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <form method="post" action="/contests/<?= (int) $c['id'] ?>/delete" style="display:inline" data-confirm="<?= $L('contests.delete_confirm') ?>">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <button class="btn btn-sm btn-danger" type="submit"><?= $L('common.delete') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
};

// Group contests into the three status sections, preserving order.
$ccSections = ['active' => [], 'scheduled' => [], 'finished' => []];
foreach ($contests as $c) {
    $st = (string) ($c['status'] ?? '');
    if ($st === 'active' || $st === 'scheduled') {
        $ccSections[$st][] = $c;
    } else {
        $ccSections['finished'][] = $c; // finished + any unknown/empty status
    }
}
?>
<?php if (!$contests): ?>
    <div class="card"><div class="empty"><?= $L('contests.none') ?></div></div>
<?php else: ?>
    <?php foreach (['active' => 'contests.status_active', 'scheduled' => 'contests.status_scheduled', 'finished' => 'contests.status_finished'] as $ccKey => $ccTitleKey): ?>
        <?php if ($ccSections[$ccKey]): ?>
            <div class="contest-section">
                <h2 class="contest-section-title"><?= $L($ccTitleKey) ?> <span class="contest-section-count"><?= count($ccSections[$ccKey]) ?></span></h2>
                <div class="contest-grid">
                    <?php foreach ($ccSections[$ccKey] as $c) { $renderContestCard($c); } ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($canManage && $retention > 0): ?>
    <p class="muted" style="margin-top:12px"><?= View::e(Lang::t('contests.retention_note', ['days' => $retention])) ?></p>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="modal-overlay" id="modal-contest">
    <div class="modal">
        <form method="post" action="/contests">
            <div class="modal-head"><h3><?= $L('contests.new_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('contests.title') ?></label><input type="text" name="title" required></div>
                <div class="field"><label><?= $L('contests.max_winners') ?></label><input type="number" name="max_winners" id="cc-max" value="3" min="1" max="50"></div>
                <div class="field"><label><?= $L('contests.start_at') ?></label><div style="display:flex;gap:8px"><input type="date" name="start_at_date" style="flex:1"><input type="text" name="start_at_time" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" style="width:90px" title="24-hour HH:MM"></div></div>
                <div class="field"><label><?= $L('contests.ends_at') ?></label><div style="display:flex;gap:8px"><input type="date" name="ends_at_date" required style="flex:1"><input type="text" name="ends_at_time" placeholder="HH:MM" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" style="width:90px" required title="24-hour HH:MM"></div></div>
                <p class="hint" style="margin-top:-6px"><?= $L('contests.start_at_hint') ?></p>

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
                    <div id="cc-prizes" class="cc-prizes"></div>
                </div>

                <div class="field">
                    <label><?= $L('contests.metric') ?></label>
                    <label class="inline-check"><input type="radio" name="metric" value="rank" checked> <?= $L('contests.metric_rank') ?></label>
                    <label class="inline-check"><input type="radio" name="metric" value="playtime"> <?= $L('contests.metric_playtime') ?></label>
                    <p class="hint"><?= $L('contests.metric_hint') ?></p>
                </div>
                <div class="field js-pt-source" style="display:none">
                    <label><?= $L('contests.playtime_source') ?></label>
                    <label class="inline-check"><input type="radio" name="playtime_source" value="all" checked> <?= $L('contests.pt_source_all') ?></label>
                    <label class="inline-check"><input type="radio" name="playtime_source" value="ct_t"> <?= $L('contests.pt_source_ct_t') ?></label>
                    <p class="hint"><?= $L('contests.pt_source_hint') ?></p>
                </div>
                <div class="field">
                    <label><?= $L('contests.mode') ?></label>
                    <label class="inline-check"><input type="radio" name="mode" value="manual" checked> <?= $L('contests.mode_manual') ?></label>
                    <label class="inline-check"><input type="radio" name="mode" value="auto"> <?= $L('contests.mode_auto') ?></label>
                    <p class="hint"><?= $L('contests.mode_hint') ?></p>
                </div>
                <div class="field">
                    <label class="inline-check"><input type="checkbox" name="reset_at_start" value="1"> <?= $L('contests.reset_at_start') ?></label>
                    <p class="hint"><?= $L('contests.reset_at_start_hint') ?></p>
                </div>
                <div class="field">
                    <label class="inline-check"><input type="checkbox" name="reset_stats" value="1"> <?= $L('contests.reset_stats') ?></label>
                    <p class="hint"><?= $L('contests.reset_stats_hint') ?></p>
                </div>
                <div class="field">
                    <label class="inline-check"><input type="checkbox" name="extend_expiring" value="1"> <?= $L('contests.extend_expiring') ?></label>
                    <p class="hint"><?= $L('contests.extend_expiring_hint') ?></p>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('contests.create') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var groups = <?= $vipGroupsJson ?: '[]' ?>;
    var creditsOn = <?= \App\Models\StoreCreditsModel::enabled() ? 'true' : 'false' ?>;
    var wrap = document.getElementById('cc-prizes');
    var maxInput = document.getElementById('cc-max');
    if (!wrap || !maxInput) return;

    var L = {
        place: <?= json_encode(Lang::t('contests.place')) ?>,
        group: <?= json_encode(Lang::t('contests.prize')) ?>,
        days: <?= json_encode(Lang::t('contests.prize_days_tip')) ?>,
        daysUnit: <?= json_encode(Lang::t('contests.days_unit')) ?>
    };

    // Default prize group for new contests: "Diamond" if it's a configured
    // group (case-insensitive), otherwise the first group in the list.
    var defaultGroup = (function () {
        for (var i = 0; i < groups.length; i++) {
            if (String(groups[i]).toLowerCase() === 'diamond') { return groups[i]; }
        }
        return groups.length ? groups[0] : 'Diamond';
    })();

    var noneLabel = <?= json_encode(Lang::t('contests.prize_no_vip')) ?>;
    function groupControl(pos) {
        if (groups.length) {
            // Empty "no VIP" option lets a place grant credits only.
            var opts = '<option value="">' + noneLabel + '</option>' + groups.map(function (g) {
                var sel = (g === defaultGroup) ? ' selected' : '';
                return '<option value="' + g.replace(/"/g, '&quot;') + '"' + sel + '>' + g + '</option>';
            }).join('');
            return '<select name="prize_group[' + pos + ']">' + opts + '</select>';
        }
        return '<input type="text" name="prize_group[' + pos + ']" value="' + defaultGroup + '" placeholder="VIP">';
    }

    function render() {
        var n = Math.max(1, Math.min(50, parseInt(maxInput.value, 10) || 1));
        // Preserve existing values across re-render.
        var prev = {};
        wrap.querySelectorAll('[data-pos]').forEach(function (row) {
            var pos = row.getAttribute('data-pos');
            var g = row.querySelector('[name^="prize_group"]');
            var d = row.querySelector('[name^="prize_days"]');
            var c = row.querySelector('[name^="prize_credits"]');
            prev[pos] = { g: g ? g.value : '', d: d ? d.value : '0', c: c ? c.value : '0' };
        });
        var html = '';
        for (var i = 1; i <= n; i++) {
            html += '<div class="cc-prize-row" data-pos="' + i + '">'
                + '<span class="cc-place">#' + i + '</span>'
                + '<div class="cc-prize-group">' + groupControl(i) + '</div>'
                + '<div class="cc-days-wrap"><input class="cc-prize-days" type="number" name="prize_days[' + i + ']" value="0" min="0" title="' + L.days + '"></div>'
                + (creditsOn ? '<div class="cc-days-wrap"><input class="cc-prize-days" type="number" name="prize_credits[' + i + ']" value="0" min="0"></div>' : '')
                + '</div>';
        }
        wrap.innerHTML = html;
        // Restore values.
        Object.keys(prev).forEach(function (pos) {
            var row = wrap.querySelector('[data-pos="' + pos + '"]');
            if (!row) return;
            var g = row.querySelector('[name^="prize_group"]');
            var d = row.querySelector('[name^="prize_days"]');
            var c = row.querySelector('[name^="prize_credits"]');
            if (g && prev[pos].g) g.value = prev[pos].g;
            if (d && prev[pos].d) d.value = prev[pos].d;
            if (c && prev[pos].c) c.value = prev[pos].c;
        });
    }

    maxInput.addEventListener('input', render);
    render();

    // Show/hide playtime_source when metric = playtime.
    var ptSource = document.querySelector('.js-pt-source');
    if (ptSource) {
        document.querySelectorAll('input[name="metric"]').forEach(function (r) {
            r.addEventListener('change', function () {
                ptSource.style.display = r.value === 'playtime' && r.checked ? '' : 'none';
            });
        });
    }
})();
</script>
<?php endif; ?>
