<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var \App\Core\Paginator|null $board @var array $totals @var string $csrf @var string $sort @var string $dir */
$title = Lang::t('nav.stats');
$L = fn(string $k) => View::e(Lang::t($k));
$isRoot = Auth::isRoot();
$offset = $board ? ($board->page - 1) * $board->perPage : 0;
$sort = $sort ?? 'credits';
$dir  = ($dir ?? 'desc') === 'asc' ? 'asc' : 'desc';
$totals = $totals ?? ['players' => 0, 'credits' => 0];

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
$sortH = function (string $col, string $label) use ($sort, $dir): string {
    $active = $sort === $col;
    $newDir = ($active && $dir === 'desc') ? 'asc' : 'desc';
    $arrow = $active ? ($dir === 'desc' ? ' ▾' : ' ▴') : '';
    $href = '/credits?sort=' . urlencode($col) . '&dir=' . $newDir;
    return '<a class="sort-h' . ($active ? ' active' : '') . '" href="' . View::e($href) . '">' . View::e($label) . $arrow . '</a>';
};
?>
<div class="subtabs">
    <a class="subtab active" href="/credits"><?= $L('credits.subtab') ?></a>
    <?php if ($isRoot): ?>
        <button class="btn btn-sm btn-danger subtab-action" data-modal="modal-credits-reset-all"><?= $L('common.reset') ?></button>
    <?php endif; ?>
</div>

<div class="credits-overview">
    <?php if ($board !== null): ?>
        <div class="credits-summary">
            <div class="credits-summary-item">
                <span class="credits-summary-label"><?= $L('credits.total_players') ?></span>
                <strong><?= number_format((int) $totals['players']) ?></strong>
            </div>
            <div class="credits-summary-item">
                <span class="credits-summary-label"><?= $L('credits.total_credits') ?></span>
                <strong><?= number_format((int) $totals['credits']) ?></strong>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($isRoot): ?>
        <button class="btn btn-primary" data-modal="modal-credits-addplayer">+ <?= $L('common.new') ?></button>
    <?php endif; ?>
</div>

<?php if ($board === null): ?>
    <div class="card"><div class="empty"><?= $L('credits.no_source') ?></div></div>
<?php else: ?>
    <?php $pg = $board; ?>
    <?php $avatars = $pg->items ? Steam::avatars(array_column($pg->items, 'steam_id')) : []; ?>

    <div class="card" style="padding:0">
        <?php if (!$pg->items): ?>
            <div class="empty"><?= $L('credits.none') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>#</th>
                        <th><?= $L('common.player') ?></th>
                        <th><?= $sortH('credits', Lang::t('credits.credits')) ?></th>
                        <th><?= $L('ranks.last_seen') ?></th>
                        <?php if ($isRoot): ?><th></th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($pg->items as $i => $r): ?>
                        <tr>
                            <td class="muted"><?= $offset + $i + 1 ?></td>
                            <td>
                                <a class="player-cell" <?= Steam::linkAttrs($r['steam_id']) ?>>
                                    <img class="player-avatar" src="<?= View::e($avatars[(string) $r['steam_id']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                                    <span class="pname"><?= View::e($r['name'] ?: 'Player') ?></span>
                                </a>
                            </td>
                            <td style="font-weight:700;color:var(--accent-2)"><?= number_format((int) ($r['credits'] ?? 0)) ?></td>
                            <td class="muted"><?= View::e($ago($r['last_online'] ?? null)) ?></td>
                            <?php if ($isRoot): ?>
                            <td style="white-space:nowrap;text-align:right">
                                <button class="btn btn-sm"
                                    data-modal="modal-credits-edit"
                                    data-set-steam_id="<?= View::e($r['steam_id']) ?>"
                                    data-set-name="<?= View::e($r['name'] ?? '') ?>"
                                    data-set-credits="<?= (int) ($r['credits'] ?? 0) ?>"><?= $L('common.edit') ?></button>
                                <button class="btn btn-sm"
                                    data-modal="modal-credits-add"
                                    data-set-steam_id="<?= View::e($r['steam_id']) ?>"
                                    data-set-name="<?= View::e($r['name'] ?? '') ?>"><?= $L('credits.add') ?></button>
                                <button class="btn btn-sm btn-danger"
                                    data-modal="modal-credits-subtract"
                                    data-set-steam_id="<?= View::e($r['steam_id']) ?>"
                                    data-set-name="<?= View::e($r['name'] ?? '') ?>"><?= $L('credits.subtract') ?></button>
                                <form method="post" action="/credits/reset" data-confirm="<?= $L('credits.reset_one') ?>" style="display:inline">
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

<?php if ($isRoot): ?>
<?php
// Online-player caveat shown in every edit dialog.
$onlineNote = $L('credits.online_note');
?>
<!-- Edit (set absolute) -->
<div class="modal-overlay" id="modal-credits-edit">
    <div class="modal">
        <form method="post" action="/credits/set">
            <div class="modal-head"><h3><?= $L('credits.edit_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="steam_id" value="">
                <input type="hidden" name="name" value="">
                <div class="field"><label><?= $L('credits.credits') ?></label><input type="number" name="credits" min="0" value="0"></div>
                <p class="hint"><?= $onlineNote ?></p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Add (delta +) -->
<div class="modal-overlay" id="modal-credits-add">
    <div class="modal">
        <form method="post" action="/credits/add">
            <div class="modal-head"><h3><?= $L('credits.add_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="steam_id" value="">
                <input type="hidden" name="name" value="">
                <div class="field"><label><?= $L('credits.amount') ?></label><input type="number" name="amount" min="1" value="100"></div>
                <p class="hint"><?= $onlineNote ?></p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('credits.add') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Subtract (delta -) -->
<div class="modal-overlay" id="modal-credits-subtract">
    <div class="modal">
        <form method="post" action="/credits/subtract">
            <div class="modal-head"><h3><?= $L('credits.subtract_title') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="steam_id" value="">
                <input type="hidden" name="name" value="">
                <div class="field"><label><?= $L('credits.amount') ?></label><input type="number" name="amount" min="1" value="100"></div>
                <p class="hint"><?= $onlineNote ?></p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-danger"><?= $L('credits.subtract') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Add player (set credits for a SteamID64 that may not have a row yet) -->
<div class="modal-overlay" id="modal-credits-addplayer">
    <div class="modal">
        <form method="post" action="/credits/set">
            <div class="modal-head"><h3><?= $L('credits.add_player') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <div class="field"><label><?= $L('common.steamid64') ?></label><input type="text" name="steam_id" placeholder="7656119..." required></div>
                <div class="field"><label><?= $L('credits.player_name') ?></label><input type="text" name="name" placeholder="Player"></div>
                <div class="field"><label><?= $L('credits.credits') ?></label><input type="number" name="credits" min="0" value="0"></div>
                <p class="hint"><?= $L('credits.add_player_hint') ?></p>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= $L('common.save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Reset all -->
<div class="modal-overlay" id="modal-credits-reset-all">
    <div class="modal">
        <form method="post" action="/credits/reset-all" id="reset-credits-form">
            <div class="modal-head"><h3><?= $L('credits.reset_all') ?></h3><button type="button" class="modal-close">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <p><?= $L('credits.reset_all_warn') ?> <?= $L('common.irreversible') ?></p>
                <label class="inline-check">
                    <input type="checkbox" name="confirm" value="yes" id="reset-credits-confirm">
                    <?= $L('credits.reset_all_ack') ?>
                </label>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost modal-close"><?= $L('common.cancel') ?></button>
                <button type="submit" class="btn btn-danger" id="reset-credits-submit" disabled><?= $L('credits.reset_all') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var c = document.getElementById('reset-credits-confirm');
    var b = document.getElementById('reset-credits-submit');
    var f = document.getElementById('reset-credits-form');
    if (c && b) c.addEventListener('change', function () { b.disabled = !c.checked; });
    if (f) f.addEventListener('submit', function (e) {
        if (!c.checked || !window.confirm(<?= json_encode(Lang::t('credits.reset_all_js')) ?>)) e.preventDefault();
    });
})();
</script>
<?php endif; ?>
