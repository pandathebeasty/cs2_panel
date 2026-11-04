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
$search = (string) ($search ?? '');
$liveSearchKey = $board !== null ? 'k4-playtime' : null;
$posOf = fn(array $r, int $i): string => isset($r['pos']) ? (string) (int) $r['pos'] : ($search !== '' ? '—' : (string) ($offset + $i + 1));
$sort = $sort ?? 'playtime';
$dir  = ($dir ?? 'desc') === 'asc' ? 'asc' : 'desc';

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
// Minutes -> "Xh Ym" (K4-System values are integer minutes from seconds/60).
$hm = function ($minutes): string {
    $m = (int) round((float) $minutes);
    $h = intdiv($m, 60);
    $rem = $m % 60;
    return $h > 0 ? ($h . 'h ' . $rem . 'm') : ($rem . 'm');
};
// Sortable header: toggles dir when clicking the active column.
$sortH = function (string $col, string $label) use ($sort, $dir, $search): string {
    $active = $sort === $col;
    $newDir = ($active && $dir === 'desc') ? 'asc' : 'desc';
    $arrow = $active ? ($dir === 'desc' ? ' ▾' : ' ▴') : '';
    $href = '/k4/playtime?sort=' . urlencode($col) . '&dir=' . $newDir . ($search !== '' ? '&search=' . urlencode($search) : '');
    return '<a class="sort-h' . ($active ? ' active' : '') . '" href="' . View::e($href) . '">' . View::e($label) . $arrow . '</a>';
};
?>
<div class="subtabs">
    <a class="subtab" href="/k4/ranks"><?= $L('ranks.subtab_ranks') ?></a>
    <a class="subtab active" href="/k4/playtime"><?= $L('ranks.subtab_playtime') ?></a>
    <?php $k4Active = ''; require __DIR__ . '/_extras_tabs.php'; ?>
    <?php if ($isRoot): ?>
        <button class="btn btn-sm btn-danger subtab-action" data-modal="modal-reset-all-playtime"><?= $L('playtime.reset_all') ?></button>
    <?php endif; ?>
</div>

<?php if ($board === null): ?>
    <div class="card"><div class="empty"><?= $L('playtime.no_source') ?></div></div>
<?php else: ?>
    <?php $pg = $board; ?>
    <div class="toolbar">
        <form method="get" data-live-search="k4-playtime" role="search">
            <input type="text" name="search" placeholder="<?= $L('search.player_ph') ?>" value="<?= View::e($search) ?>" maxlength="64">
        </form>
    </div>
    <?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
    <div class="live-search-results" id="search-results-k4-playtime" data-live-search-results="k4-playtime" tabindex="-1">
    <?php $avatars = $pg->items ? Steam::avatars(array_column($pg->items, 'steam_id')) : []; ?>
    <div class="card" style="padding:0">
        <?php if (!$pg->items): ?>
            <div class="empty"><?= $search !== '' ? $L('k4x.no_match') : $L('playtime.none') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>#</th>
                        <th><?= $L('common.player') ?></th>
                        <th><?= $sortH('playtime', Lang::t('playtime.all')) ?></th>
                        <th><?= $sortH('ct', 'CT') ?></th>
                        <th><?= $sortH('t', 'T') ?></th>
                        <th><?= $sortH('spec', Lang::t('playtime.spec')) ?></th>
                        <th><?= $sortH('dead', Lang::t('playtime.dead')) ?></th>
                        <th><?= $sortH('alive', Lang::t('playtime.alive')) ?></th>
                        <th><?= $L('ranks.last_seen') ?></th>
                        <?php if ($canManage): ?><th></th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($pg->items as $i => $r): ?>
                        <tr>
                            <td class="muted"><?= $posOf($r, $i) ?></td>
                            <td>
                                <a class="player-cell" <?= Steam::linkAttrs($r['steam_id']) ?>>
                                    <img class="player-avatar" src="<?= View::e($avatars[(string) $r['steam_id']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                                    <span class="pname"><?= View::e(Steam::displayName($r['steam_id'], $r['name']) ?: 'Player') ?></span>
                                </a>
                            </td>
                            <td style="font-weight:700;color:var(--accent-2)"><?= View::e($hm($r['playtime_minutes'] ?? 0)) ?></td>
                            <td class="muted"><?= View::e($hm($r['ct_minutes'] ?? 0)) ?></td>
                            <td class="muted"><?= View::e($hm($r['t_minutes'] ?? 0)) ?></td>
                            <td class="muted"><?= View::e($hm($r['spec_minutes'] ?? 0)) ?></td>
                            <td class="muted"><?= View::e($hm($r['dead_minutes'] ?? 0)) ?></td>
                            <td class="muted"><?= View::e($hm($r['alive_minutes'] ?? 0)) ?></td>
                            <td class="muted"><?= View::e($ago($r['last_online'] ?? null)) ?></td>
                            <?php if ($canManage): ?>
                            <td style="white-space:nowrap">
                                <button class="btn btn-sm"
                                    data-modal="modal-edit-playtime"
                                    data-set-steam_id="<?= View::e($r['steam_id']) ?>"
                                    data-set-minutes="<?= (int) ($r['playtime_minutes'] ?? 0) ?>"><?= $L('common.edit') ?></button>
                                <form method="post" action="/k4/playtime/reset" data-confirm="<?= $L('playtime.reset_one') ?>" style="display:inline">
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
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="modal-overlay" id="modal-edit-playtime">
    <div class="modal">
        <form method="post" action="/k4/playtime/edit">
            <div class="modal-head"><h3><?= $L('playtime.edit_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="steam_id" value="">
                <div class="field"><label><?= $L('playtime.minutes') ?></label><input type="number" name="minutes" min="0" value="0"></div>
                <p class="hint"><?= $L('playtime.minutes_hint') ?></p>
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
<div class="modal-overlay" id="modal-reset-all-playtime">
    <div class="modal">
        <form method="post" action="/k4/playtime/reset-all" id="reset-playtime-form">
            <div class="modal-head"><h3><?= $L('playtime.reset_all') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <p><?= $L('playtime.reset_all_warn') ?> <?= $L('common.irreversible') ?></p>
                <label class="inline-check">
                    <input type="checkbox" name="confirm" value="yes" id="reset-playtime-confirm">
                    <?= $L('playtime.reset_all_ack') ?>
                </label>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-danger" id="reset-playtime-submit" disabled><?= $L('playtime.reset_all') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var c = document.getElementById('reset-playtime-confirm');
    var b = document.getElementById('reset-playtime-submit');
    var f = document.getElementById('reset-playtime-form');
    if (c && b) c.addEventListener('change', function () { b.disabled = !c.checked; });
    if (f) f.addEventListener('submit', function (e) {
        if (!c.checked || !window.confirm(<?= json_encode(Lang::t('playtime.reset_all_js')) ?>)) e.preventDefault();
    });
})();
</script>
<?php endif; ?>
