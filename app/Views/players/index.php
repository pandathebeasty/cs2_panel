<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var string $q @var array<string,string> $players @var array $roles @var array $avatars @var array $seen @var ?\App\Core\Paginator $pg */
$title = Lang::t('players.title');
$L = fn(string $k) => View::e(Lang::t($k));
$liveSearchKey = 'players';
$roles = $roles ?? [];
$seen = $seen ?? [];
$pg = $pg ?? null;
$searching = mb_strlen($q) >= 2;
$total = $pg !== null ? max($pg->total, count($players)) : count($players);
$badge = static function (string $kind, string $group): string {
    $label = strcasecmp($group, $kind) === 0 ? $kind : $kind . ' · ' . $group;
    $cls = $kind === 'VIP' ? 'badge-perm' : 'badge-role-admin';
    return '<span class="badge ' . $cls . '" title="' . View::e($group) . '">' . View::e($label) . '</span>';
};
?>
<div class="x-page-head">
    <div>
        <span class="x-eyebrow"><?= $L('nav.moderation') ?></span>
        <h1 class="x-page-title"><?= $L('players.title') ?></h1>
        <p class="muted"><?= $L('players.sub') ?></p>
    </div>
</div>

<form class="card x-section" method="get" action="<?= View::e(url('/players')) ?>" role="search" data-live-search="players">
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <input type="search" name="search" value="<?= View::e($q) ?>" placeholder="<?= $L('players.search_ph') ?>" aria-label="<?= $L('players.search_ph') ?>" style="flex:1;min-width:220px" autofocus>
        <button type="submit" class="btn btn-primary"><?= $L('common.search') ?></button>
    </div>
</form>

<?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
<div class="live-search-results" id="search-results-players" data-live-search-results="players" tabindex="-1">
<?php if ($pg === null): ?><span data-live-search-meta data-total="<?= count($players) ?>" data-page="1" hidden></span><?php endif; ?>
<div class="card">
    <div class="card-title x-title-row"><?= $searching ? $L('players.results') : ($pg !== null ? $L('players.all') : $L('players.recent')) ?> <span class="x-count"><?= number_format($total) ?></span></div>
    <?php if (!$players): ?>
        <div class="x-empty x-empty-lg"><?= $L('players.none') ?></div>
    <?php else: ?>
        <div class="table-wrap"><table class="x-stack">
            <thead><tr><th><?= $L('players.player') ?></th><th><?= $L('players.role') ?></th><th>SteamID64</th><?php if ($pg !== null): ?><th><?= $L('players.last_seen') ?></th><?php endif; ?><th></th></tr></thead>
            <tbody>
            <?php foreach ($players as $sid => $name): $sid = (string) $sid; $r = $roles[$sid] ?? []; ?>
                <tr>
                    <td data-label="<?= $L('players.player') ?>"><?= Steam::playerCell($sid, $name, $avatars) ?></td>
                    <td data-label="<?= $L('players.role') ?>">
                        <?php if (empty($r['admin']) && empty($r['vip'])): ?><span class="muted">—</span><?php endif; ?>
                        <?php if (!empty($r['admin'])): ?><?= $badge('Admin', (string) $r['admin']) ?><?php endif; ?>
                        <?php if (!empty($r['vip'])): ?><?= $badge('VIP', (string) $r['vip']) ?><?php endif; ?>
                    </td>
                    <td data-label="SteamID64" class="mono"><?= View::e($sid) ?></td>
                    <?php if ($pg !== null): ?>
                    <td data-label="<?= $L('players.last_seen') ?>" class="muted" style="white-space:nowrap"><?= !empty($seen[$sid]) ? View::e(date('Y-m-d H:i', strtotime((string) $seen[$sid]) ?: 0)) : '—' ?></td>
                    <?php endif; ?>
                    <td style="text-align:right;white-space:nowrap">
                        <a class="btn btn-sm" href="<?= View::e(url('/player/' . $sid)) ?>"><?= $L('players.open') ?></a>
                        <a class="btn btn-sm btn-ghost" href="<?= View::e(Steam::profileUrl($sid)) ?>" target="_blank" rel="noopener">Steam ↗</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php require BASE_PATH . '/app/Views/partials/pagination.php'; ?>
</div>
