<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
use App\Models\K4ExtrasModel;
/**
 * @var array $list @var array $recent @var array $leaders @var array $rarity
 * @var ?string $steamId @var ?array $player @var bool $isSelf
 */
$title = Lang::t('nav.k4_achievements');
$L = fn(string $k) => View::e(Lang::t($k));
$icon = fn(string $url, string $cls = '') => '<span class="k4x-icon ' . $cls . '" style="--i:url(\'' . View::e($url) . '\')"></span>';

$total = count($list);
$unlocked = array_values(array_filter($list, fn($a) => $a['unlocked']));
$earned = array_sum(array_column($unlocked, 'points'));
$pct = $total > 0 ? (int) round(count($unlocked) * 100 / $total) : 0;
// Unlocked first (newest first), then closest to done, then rarest.
usort($list, function ($a, $b) {
    if ($a['unlocked'] !== $b['unlocked']) return $a['unlocked'] ? -1 : 1;
    if ($a['unlocked']) return strcmp((string) $b['unlocked_at'], (string) $a['unlocked_at']);
    return $b['percent'] <=> $a['percent'] ?: $a['goal'] <=> $b['goal'];
});
$fmtDate = fn(?string $d) => $d ? date('d.m.Y', strtotime($d) ?: time()) : '';
?>
<?php require __DIR__ . '/_extras_head.php'; ?>

<div class="k4x-layout">
    <section class="k4x-main">
        <?php if ($steamId): ?>
            <div class="k4x-summary card">
                <div class="k4x-ring k4x-ring-sm" style="--p:<?= $pct ?>">
                    <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="trk" cx="60" cy="60" r="52"/><circle class="val" cx="60" cy="60" r="52" pathLength="100"/></svg>
                    <strong><?= $pct ?>%</strong>
                </div>
                <div class="k4x-summary-stats">
                    <div><span><?= $L('k4x.unlocked') ?></span><strong><span data-k4x-count="<?= count($unlocked) ?>"><?= count($unlocked) ?></span> / <?= $total ?></strong></div>
                    <div><span><?= $L('k4x.points_earned') ?></span><strong>+<span data-k4x-count="<?= (int) $earned ?>"><?= number_format((int) $earned) ?></span></strong></div>
                    <?php foreach (['gold', 'silver', 'bronze'] as $tier): ?>
                        <?php $n = count(array_filter($unlocked, fn($a) => $a['tier'] === $tier)); ?>
                        <div class="k4x-medal-count tier-<?= $tier ?>"><span class="k4x-medal"></span><strong><?= $n ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="k4x-section-head">
            <h2><?= $icon(K4ExtrasModel::uiIcon('trophy')) ?><?= $L('nav.k4_achievements') ?></h2>
            <div class="k4x-tools">
                <?php if ($list): ?><input type="search" class="k4x-find" data-k4x-find="#k4x-ach-grid" placeholder="<?= $L('search.filter_ph') ?>" aria-label="<?= $L('common.search') ?>" autocomplete="off"><?php endif; ?>
            <?php if ($steamId): ?>
                <div class="k4x-filter" data-k4x-filter="#k4x-ach-grid">
                    <button type="button" class="active" data-f="all"><?= $L('common.all') ?></button>
                    <button type="button" data-f="unlocked"><?= $L('k4x.unlocked') ?></button>
                    <button type="button" data-f="progress"><?= $L('k4x.in_progress') ?></button>
                    <button type="button" data-f="locked"><?= $L('k4x.locked') ?></button>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <?php if (!$list): ?>
            <div class="card k4x-empty"><?= $icon(K4ExtrasModel::uiIcon('trophy'), 'k4x-icon-xl') ?><p><?= $L('k4x.no_achievements') ?></p></div>
        <?php else: ?>
            <div class="k4x-achievements" id="k4x-ach-grid">
                <?php foreach ($list as $i => $a): ?>
                    <?php $state = !$steamId ? 'catalog' : ($a['unlocked'] ? 'unlocked' : ($a['progress'] > 0 ? 'progress' : 'locked')); ?>
                    <article class="k4x-ach tier-<?= $a['tier'] ?> is-<?= $state ?>" data-state="<?= $state ?>" data-k4x-text="<?= View::e(mb_strtolower($a['name'] . ' ' . $a['goal_text'])) ?>" style="--p:<?= (int) $a['percent'] ?>;--d:<?= min($i, 20) * 35 ?>ms">
                        <div class="k4x-ach-badge">
                            <?= $icon($a['icon'], 'k4x-icon-lg') ?>
                            <?php if (!$a['unlocked'] && $steamId): ?><span class="k4x-lock"><?= $icon(K4ExtrasModel::uiIcon('locked')) ?></span><?php endif; ?>
                        </div>
                        <div class="k4x-ach-body">
                            <div class="k4x-ach-top">
                                <h3><?= View::e($a['name']) ?></h3>
                                <span class="k4x-reward">+<?= number_format((int) $a['points']) ?></span>
                            </div>
                            <p class="muted"><?= View::e($a['goal_text']) ?></p>
                            <?php if ($steamId): ?>
                                <div class="k4x-bar k4x-bar-sm"><span></span></div>
                                <div class="k4x-mission-foot">
                                    <span><?= $a['unlocked'] ? View::e($fmtDate($a['unlocked_at'])) : ($a['tracked'] ? number_format((int) $a['progress']) . ' / ' . number_format((int) $a['goal']) : '—') ?></span>
                                    <span title="<?= View::e(Lang::t('k4x.rarity_hint', ['n' => $a['holders']])) ?>"><?= View::e(rtrim(rtrim(number_format((float) $a['rarity'], 1), '0'), '.')) ?>% <?= $L('k4x.of_players') ?></span>
                                </div>
                            <?php else: ?>
                                <div class="k4x-mission-foot"><span></span><span><?= View::e(rtrim(rtrim(number_format((float) $a['rarity'], 1), '0'), '.')) ?>% <?= $L('k4x.of_players') ?></span></div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <div class="empty k4x-find-empty" data-k4x-find-empty hidden><?= $L('search.no_match') ?></div>
            </div>
        <?php endif; ?>
    </section>

    <aside class="k4x-side">
        <div class="card k4x-board">
            <div class="card-title"><?= $icon(K4ExtrasModel::uiIcon('mission_star')) ?><?= $L('k4x.recent_unlocks') ?></div>
            <?php if (!$recent): ?>
                <div class="muted k4x-board-empty"><?= $L('k4x.no_unlocks') ?></div>
            <?php else: ?>
                <?php foreach ($recent as $i => $r): ?>
                    <a class="k4x-feed tier-<?= View::e($r['tier']) ?>" href="/k4/achievements?player=<?= View::e((string) $r['steam_id']) ?>" style="--d:<?= $i * 40 ?>ms">
                        <span class="k4x-feed-badge"><?= $icon($r['icon']) ?></span>
                        <span class="k4x-feed-text">
                            <strong><?= View::e($r['title']) ?></strong>
                            <span class="muted"><?= View::e(Steam::displayName((string) $r['steam_id'], (string) $r['name'])) ?> · <?= View::e($fmtDate((string) $r['unlocked_at'])) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card k4x-board">
            <div class="card-title"><?= $icon(K4ExtrasModel::uiIcon('leader')) ?><?= $L('k4x.top_collectors') ?></div>
            <?php if (!$leaders): ?>
                <div class="muted k4x-board-empty"><?= $L('k4x.no_unlocks') ?></div>
            <?php else: ?>
                <?php $lAv = Steam::avatars(array_column($leaders, 'steam_id')); ?>
                <?php foreach ($leaders as $i => $s): ?>
                    <a class="k4x-board-row" href="/k4/achievements?player=<?= View::e((string) $s['steam_id']) ?>" style="--d:<?= $i * 40 ?>ms">
                        <span class="k4x-pos pos-<?= $i + 1 ?>"><?= $i + 1 ?></span>
                        <img src="<?= View::e($lAv[(string) $s['steam_id']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                        <span class="k4x-board-name"><?= View::e(Steam::displayName((string) $s['steam_id'], (string) $s['name'])) ?></span>
                        <span class="k4x-board-val"><?= (int) $s['total'] ?>/<?= $total ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>
<?php require __DIR__ . '/_extras_script.php'; ?>
