<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
use App\Models\K4ExtrasModel;
/**
 * @var array $seasons @var string $season @var ?string $current @var \App\Core\Paginator $board @var array $history
 * @var array $countdowns @var ?string $steamId @var ?array $player @var bool $isSelf
 */
$title = Lang::t('nav.k4_seasons');
$L = fn(string $k) => View::e(Lang::t($k));
$icon = fn(string $url, string $cls = '') => '<span class="k4x-icon ' . $cls . '" style="--i:url(\'' . View::e($url) . '\')"></span>';
$live = $season === $current;
$pg = $board;
$offset = ($pg->page - 1) * $pg->perPage;
$search = (string) ($search ?? '');
$liveSearchKey = 'k4-seasons';
$podium = $pg->page === 1 && $search === '' ? array_slice($pg->items, 0, 3) : [];
$avatars = $pg->items ? Steam::avatars(array_column($pg->items, 'steam_id')) : [];
?>
<?php require __DIR__ . '/_extras_head.php'; ?>

<div class="k4x-season-hero card<?= $live ? ' is-live' : '' ?>">
    <div class="k4x-season-title">
        <span class="k4x-eyebrow"><?= $live ? $L('k4x.current_season') : $L('k4x.past_season') ?></span>
        <h2><?= View::e(K4ExtrasModel::seasonLabel($season)) ?></h2>
        <?php if ($live): ?>
            <p class="muted"><?= $L('k4x.season_ends_in') ?> <strong data-k4x-countdown="<?= (int) $countdowns['season'] ?>"></strong></p>
        <?php else: ?>
            <p class="muted"><?= $L('k4x.final_standings') ?></p>
        <?php endif; ?>
    </div>
    <?php if (count($seasons) > 1): ?>
        <div class="k4x-season-picker">
            <?php foreach (array_slice($seasons, 0, 12) as $s): ?>
                <a class="k4x-chip<?= $s === $season ? ' active' : '' ?>" href="/k4/seasons?season=<?= View::e($s) ?><?= $steamId ? '&player=' . View::e($steamId) : '' ?>">
                    <?= View::e(K4ExtrasModel::seasonLabel($s)) ?><?php if ($s === $current): ?><span class="k4x-live-dot"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="toolbar">
    <form method="get" data-live-search="k4-seasons" role="search">
        <input type="hidden" name="season" value="<?= View::e($season) ?>">
        <?php if ($steamId): ?><input type="hidden" name="player" value="<?= View::e($steamId) ?>"><?php endif; ?>
        <input type="text" name="search" placeholder="<?= $L('search.player_ph') ?>" value="<?= View::e($search) ?>" maxlength="64">
    </form>
</div>
<?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
<div class="live-search-results" id="search-results-k4-seasons" data-live-search-results="k4-seasons" tabindex="-1">
<?php if ($podium): ?>
    <div class="k4x-podium">
        <?php foreach ([1, 0, 2] as $slot): ?>
            <?php if (!isset($podium[$slot])) continue; $r = $podium[$slot]; ?>
            <a class="k4x-podium-step place-<?= $slot + 1 ?>" href="/k4/seasons?season=<?= View::e($season) ?>&player=<?= View::e((string) $r['steam_id']) ?>" style="--d:<?= [1 => 0, 0 => 120, 2 => 240][$slot] ?>ms">
                <span class="k4x-podium-crown"><?= $icon(K4ExtrasModel::uiIcon($slot === 0 ? 'trophy' : 'winner_ribbon')) ?></span>
                <img src="<?= View::e($avatars[(string) $r['steam_id']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                <strong><?= View::e(Steam::displayName((string) $r['steam_id'], (string) $r['name'])) ?></strong>
                <span class="k4x-podium-pts"><?= number_format((int) $r['points']) ?></span>
                <span class="k4x-podium-block"><?= $slot + 1 ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="k4x-layout">
    <section class="k4x-main">
        <div class="card" style="padding:0">
            <?php if (!$pg->items): ?>
                <div class="empty"><?= $search !== '' ? $L('k4x.no_match') : $L('k4x.no_season_data') ?></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>#</th><th><?= $L('common.player') ?></th><th><?= $live ? $L('k4x.points') : $L('k4x.final_points') ?></th><th><?= $L('k4x.peak') ?></th><th><?= $L('dash.rank') ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($pg->items as $i => $r): ?>
                            <tr class="<?= (string) $r['steam_id'] === (string) $steamId ? 'k4x-me-row' : '' ?>">
                                <td class="muted"><?= isset($r['pos']) ? (int) $r['pos'] : ($search !== '' ? '—' : $offset + $i + 1) ?></td>
                                <td><?= Steam::playerCell((string) $r['steam_id'], (string) ($r['name'] ?: Lang::t('common.player')), $avatars) ?></td>
                                <td style="font-weight:700"><?= number_format((int) $r['points']) ?></td>
                                <td class="muted"><?= number_format((int) $r['peak_points']) ?></td>
                                <td class="muted"><?= View::e((string) ($r['rank_name'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php require BASE_PATH . '/app/Views/partials/pagination.php'; ?>
    </section>

    <aside class="k4x-side">
        <div class="card k4x-board">
            <div class="card-title"><?= $icon(K4ExtrasModel::uiIcon('xp_rank')) ?><?= $isSelf ? $L('k4x.your_seasons') : $L('k4x.player_seasons') ?></div>
            <?php if (!$steamId): ?>
                <div class="muted k4x-board-empty"><?= $L('k4x.pick_player_hint') ?></div>
            <?php elseif (!$history): ?>
                <div class="muted k4x-board-empty"><?= $L('k4x.no_season_history') ?></div>
            <?php else: ?>
                <?php foreach ($history as $i => $h): ?>
                    <a class="k4x-history" href="/k4/seasons?season=<?= View::e((string) $h['season']) ?>&player=<?= View::e($steamId) ?>" style="--d:<?= $i * 40 ?>ms">
                        <span class="k4x-history-pos<?= $h['position'] !== null && $h['position'] <= 3 ? ' top' : '' ?>"><?= $h['position'] !== null ? '#' . (int) $h['position'] : '<span class="k4x-live-dot"></span>' ?></span>
                        <span class="k4x-feed-text">
                            <strong><?= View::e(K4ExtrasModel::seasonLabel((string) $h['season'])) ?></strong>
                            <span class="muted"><?= View::e((string) ($h['rank_name'] ?? '')) ?></span>
                        </span>
                        <span class="k4x-board-val"><?= number_format((int) ($h['points'] ?? $h['peak_points'])) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>
</div>
<?php require __DIR__ . '/_extras_script.php'; ?>
