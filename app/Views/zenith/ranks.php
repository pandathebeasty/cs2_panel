<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var \App\Core\Paginator|null $board @var string $csrf @var string $sort @var string $dir */
$title = Lang::t('nav.stats');
$L = fn(string $k) => View::e(Lang::t($k));
$canManage = Auth::canManage();
$isRoot = Auth::isRoot();
$offset = $board ? ($board->page - 1) * $board->perPage : 0;
$sort = $sort ?? 'points';
$dir  = ($dir ?? 'desc') === 'asc' ? 'asc' : 'desc';
// Rank display style: 'premier' shows the CS2 rating, else competitive badges.
// Only K4-System distinguishes a rank type; Zenith always uses competitive-style names.
$rankType = \App\Models\StatsBackend::isK4System()
    ? (string) \App\Core\Settings::get('k4_rank_type', 'competitive')
    : 'competitive';

// Sortable header link (toggles dir on the active column).
$sortH = function (string $col, string $label) use ($sort, $dir): string {
    $active = $sort === $col;
    $newDir = ($active && $dir === 'desc') ? 'asc' : 'desc';
    $arrow = $active ? ($dir === 'desc' ? ' ▾' : ' ▴') : '';
    $href = '/zenith/ranks?sort=' . urlencode($col) . '&dir=' . $newDir;
    return '<a class="sort-h' . ($active ? ' active' : '') . '" href="' . View::e($href) . '">' . View::e($label) . $arrow . '</a>';
};

$ago = function (?string $dt): string {
    if (!$dt) return '';
    $ts = is_numeric($dt) ? (int) $dt : strtotime((string) $dt);
    if ($ts === false || $ts <= 0) return '';
    $diff = max(0, time() - $ts);
    $u = [[31536000,'time.year','time.years'],[2592000,'time.month','time.months'],[604800,'time.week','time.weeks'],[86400,'time.day','time.days'],[3600,'time.hour','time.hours'],[60,'time.minute','time.minutes']];
    foreach ($u as [$s,$one,$many]) {
        if ($diff >= $s) { $n = (int) floor($diff/$s); return Lang::t('time.ago', ['n' => $n, 'unit' => Lang::t($n > 1 ? $many : $one)]); }
    }
    return Lang::t('time.just_now');
};
?>
<div class="subtabs">
    <a class="subtab active" href="/zenith/ranks"><?= $L('ranks.subtab_ranks') ?></a>
    <a class="subtab" href="/zenith/playtime"><?= $L('ranks.subtab_playtime') ?></a>
    <?php if ($isRoot): ?>
        <div class="subtab-actions subtab-action">
            <button class="btn btn-sm btn-danger" data-modal="modal-reset-all-ranks"><?= $L('ranks.reset_all_ranks') ?></button>
            <button class="btn btn-sm btn-danger" data-modal="modal-reset-all-stats"><?= $L('ranks.reset_all_stats') ?></button>
        </div>
    <?php endif; ?>
</div>

<?php if ($board === null): ?>
    <div class="card"><div class="empty"><?= $L('ranks.no_source') ?></div></div>
<?php else: ?>
    <?php $pg = $board; ?>
    <?php $avatars = $pg->items ? Steam::avatars(array_column($pg->items, 'steam_id')) : []; ?>
    <div class="card" style="padding:0">
        <?php if (!$pg->items): ?>
            <div class="empty"><?= $L('ranks.no_players') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>#</th><th><?= $L('common.player') ?></th><th><?= $sortH('points', Lang::t('ranks.cs_rating')) ?></th><th><?= $L('dash.rank') ?></th>
                        <th><?= $sortH('kills', Lang::t('dash.kills')) ?></th><th><?= $sortH('deaths', Lang::t('dash.deaths')) ?></th><th><?= $sortH('assists', Lang::t('ranks.assists')) ?></th><th><?= $sortH('headshots', Lang::t('ranks.hs')) ?></th>
                        <th><?= $sortH('rounds_ct', Lang::t('ranks.rounds_ct')) ?></th><th><?= $sortH('rounds_t', Lang::t('ranks.rounds_t')) ?></th><th><?= $sortH('rounds_overall', Lang::t('ranks.rounds')) ?></th>
                        <th><?= $sortH('games_won', Lang::t('ranks.won')) ?></th><th><?= $sortH('games_lost', Lang::t('ranks.lost')) ?></th>
                        <?php if ($canManage): ?><th></th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($pg->items as $i => $r): ?>
                        <?php $seen = $ago($r['last_online'] ?? null); ?>
                        <tr>
                            <td class="muted"><?= $offset + $i + 1 ?></td>
                            <td>
                                <a class="player-cell" <?= Steam::linkAttrs($r['steam_id']) ?>>
                                    <img class="player-avatar" src="<?= View::e($avatars[(string) $r['steam_id']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                                    <span>
                                        <span class="pname"><?= View::e(Steam::displayName($r['steam_id'], $r['name']) ?: 'Player') ?></span>
                                        <?php if ($seen !== ''): ?><div class="muted" style="font-size:11px"><?= $L('ranks.last_seen') ?>: <?= View::e($seen) ?></div><?php endif; ?>
                                    </span>
                                </a>
                            </td>
                            <td><?= \App\Core\RankIcon::html('', 'premier', (int) ($r['points'] ?? 0)) ?></td>
                            <td>
                                <span class="rank-cell">
                                    <?= \App\Core\RankIcon::html((string) ($r['rank_name'] ?? ''), 'competitive', (int) ($r['points'] ?? 0)) ?>
                                    <span class="rank-name"><?= View::e(\App\Core\RankIcon::cleanName((string) ($r['rank_name'] ?? ''))) ?></span>
                                </span>
                            </td>
                            <td style="color:var(--green);font-weight:600"><?= number_format((int) $r['kills']) ?></td>
                            <td class="muted"><?= number_format((int) $r['deaths']) ?></td>
                            <td class="muted"><?= number_format((int) $r['assists']) ?></td>
                            <td class="muted"><?= number_format((int) $r['headshots']) ?></td>
                            <td class="muted"><?= number_format((int) $r['rounds_ct']) ?></td>
                            <td class="muted"><?= number_format((int) $r['rounds_t']) ?></td>
                            <td class="muted"><?= number_format((int) $r['rounds_overall']) ?></td>
                            <td class="muted"><?= number_format((int) $r['games_won']) ?></td>
                            <td class="muted"><?= number_format((int) $r['games_lost']) ?></td>
                            <?php if ($canManage): ?>
                            <td style="white-space:nowrap">
                                <button class="btn btn-sm"
                                    data-modal="modal-edit-points"
                                    data-set-steam_id="<?= View::e($r['steam_id']) ?>"
                                    data-set-points="<?= (int) $r['points'] ?>"><?= $L('ranks.edit_points') ?></button>
                                <button class="btn btn-sm"
                                    data-modal="modal-edit-stats"
                                    data-set-steam_id="<?= View::e($r['steam_id']) ?>"
                                    data-set-kills="<?= (int) $r['kills'] ?>"
                                    data-set-deaths="<?= (int) $r['deaths'] ?>"
                                    data-set-assists="<?= (int) $r['assists'] ?>"
                                    data-set-headshots="<?= (int) $r['headshots'] ?>"><?= $L('ranks.edit_stats') ?></button>
                                <form method="post" action="/zenith/ranks/reset" data-confirm="<?= $L('ranks.reset_one') ?>" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                    <input type="hidden" name="steam_id" value="<?= View::e($r['steam_id']) ?>">
                                    <button class="btn btn-sm btn-danger" type="submit"><?= $L('common.reset') ?></button>
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
<?php endif; ?>

<?php if ($canManage): ?>
<!-- Edit points -->
<div class="modal-overlay" id="modal-edit-points">
    <div class="modal">
        <form method="post" action="/zenith/ranks/edit-points">
            <div class="modal-head"><h3><?= $L('ranks.edit_points') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="steam_id" value="">
                <div class="field"><label><?= $L('ranks.cs_rating') ?></label><input type="number" name="points" min="0" value="0"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.save') ?></button>
            </div>
        </form>
    </div>
</div>
<!-- Edit stats -->
<div class="modal-overlay" id="modal-edit-stats">
    <div class="modal">
        <form method="post" action="/zenith/ranks/edit-stats">
            <div class="modal-head"><h3><?= $L('ranks.edit_stats') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="steam_id" value="">
                <div class="form-row">
                    <div class="field"><label><?= $L('dash.kills') ?></label><input type="number" name="kills" min="0" value="0"></div>
                    <div class="field"><label><?= $L('dash.deaths') ?></label><input type="number" name="deaths" min="0" value="0"></div>
                </div>
                <div class="form-row">
                    <div class="field"><label><?= $L('ranks.assists') ?></label><input type="number" name="assists" min="0" value="0"></div>
                    <div class="field"><label><?= $L('ranks.hs') ?></label><input type="number" name="headshots" min="0" value="0"></div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.save') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($isRoot): ?>
<div class="modal-overlay" id="modal-reset-all-ranks">
    <div class="modal">
        <form method="post" action="/zenith/ranks/reset-all" id="reset-ranks-form">
            <div class="modal-head"><h3><?= $L('ranks.reset_all_ranks') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <p><?= $L('ranks.reset_all_ranks_warn') ?> <?= $L('common.irreversible') ?></p>
                <label class="inline-check">
                    <input type="checkbox" name="confirm" value="yes" id="reset-ranks-confirm">
                    <?= $L('ranks.reset_all_ranks_ack') ?>
                </label>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-danger" id="reset-ranks-submit" disabled><?= $L('ranks.reset_all_ranks') ?></button>
            </div>
        </form>
    </div>
</div>
<div class="modal-overlay" id="modal-reset-all-stats">
    <div class="modal">
        <form method="post" action="/zenith/stats/reset-all" id="reset-stats-form">
            <div class="modal-head"><h3><?= $L('ranks.reset_all_stats_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <p><?= $L('ranks.reset_all_stats_warn') ?> <?= $L('common.irreversible') ?></p>
                <label class="inline-check">
                    <input type="checkbox" name="confirm" value="yes" id="reset-stats-confirm">
                    <?= $L('ranks.reset_all_stats_ack') ?>
                </label>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-danger" id="reset-stats-submit" disabled><?= $L('ranks.reset_all_stats') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    function guard(prefix, msg) {
        var c = document.getElementById(prefix + '-confirm');
        var b = document.getElementById(prefix + '-submit');
        var f = document.getElementById(prefix + '-form');
        if (c && b) c.addEventListener('change', function () { b.disabled = !c.checked; });
        if (f) f.addEventListener('submit', function (e) {
            if (!c.checked || !window.confirm(msg)) e.preventDefault();
        });
    }
    guard('reset-ranks', <?= json_encode(Lang::t('ranks.reset_all_ranks_js')) ?>);
    guard('reset-stats', <?= json_encode(Lang::t('ranks.reset_all_stats_js')) ?>);
})();
</script>
<?php endif; ?>
