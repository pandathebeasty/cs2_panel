<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
use App\Models\K4ExtrasModel;
/**
 * @var ?array $mine @var array $active @var array $overview @var array $popular @var array $streaks @var array $countdowns
 * @var ?string $steamId @var ?array $player @var bool $isSelf
 */
$title = Lang::t('nav.k4_missions');
$L = fn(string $k) => View::e(Lang::t($k));
$icon = fn(string $url, string $cls = '') => '<span class="k4x-icon ' . $cls . '" style="--i:url(\'' . View::e($url) . '\')"></span>';

$card = function (array $m, int $n) use ($L, $icon): string {
    ob_start(); ?>
    <article class="k4x-mission diff-<?= View::e(strtolower($m['difficulty'])) ?><?= $m['completed'] ? ' is-done' : '' ?>" style="--p:<?= (int) $m['percent'] ?>;--d:<?= $n * 90 ?>ms">
        <div class="k4x-mission-top">
            <span class="k4x-diff"><?= View::e($m['difficulty_label']) ?></span>
            <span class="k4x-reward">+<?= number_format((int) $m['reward']) ?></span>
        </div>
        <div class="k4x-ring">
            <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="trk" cx="60" cy="60" r="52"/><circle class="val" cx="60" cy="60" r="52" pathLength="100"/></svg>
            <?= $icon($m['icon'], 'k4x-icon-xl') ?>
            <?php if ($m['completed']): ?><span class="k4x-done-mark"><?= $icon(K4ExtrasModel::uiIcon('check')) ?></span><?php endif; ?>
        </div>
        <h3 class="k4x-mission-title"><?= View::e($m['title']) ?></h3>
        <div class="k4x-bar"><span></span></div>
        <div class="k4x-mission-foot">
            <?php if (isset($m['assigned'])): ?>
                <span title="<?= $L('k4x.players_done_hint') ?>"><?= $icon(K4ExtrasModel::uiIcon('check'), 'k4x-icon-xs') ?><?= number_format((int) $m['done']) ?> / <?= number_format((int) $m['assigned']) ?></span>
                <span><?= $L('k4x.target') ?> <?= number_format((int) $m['target']) ?></span>
            <?php else: ?>
                <span><?= number_format((int) $m['progress']) ?> / <?= number_format((int) $m['target']) ?></span>
                <span><?= $m['completed'] ? $L('k4x.completed') : (int) $m['percent'] . '%' ?></span>
            <?php endif; ?>
        </div>
    </article>
    <?php return (string) ob_get_clean();
};
?>
<?php require __DIR__ . '/_extras_head.php'; ?>

<div class="k4x-layout">
    <section class="k4x-main">
        <div class="k4x-section-head">
            <div>
                <h2><?= $icon(K4ExtrasModel::uiIcon('quest')) ?><?= $L('k4x.daily_missions') ?></h2>
                <p class="muted"><?= $L('k4x.new_missions_in') ?> <strong data-k4x-countdown="<?= (int) $countdowns['day'] ?>"></strong></p>
            </div>
            <?php if ($mine): ?>
                <div class="k4x-pills">
                    <span class="k4x-pill is-hot<?= $mine['streak'] > 0 ? ' on' : '' ?>"><?= $icon(K4ExtrasModel::uiIcon('hot_trend')) ?><?= (int) $mine['streak'] ?> <?= $L('k4x.streak_days') ?></span>
                    <span class="k4x-pill"><?= $icon(K4ExtrasModel::uiIcon('check')) ?><?= (int) $mine['done'] ?>/<?= max(3, count($mine['daily'])) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$steamId && $active): ?>
            <p class="muted k4x-active-note"><?= $L('k4x.active_today_hint') ?></p>
            <div class="k4x-missions">
                <?php foreach ($active as $i => $m): ?><?= $card($m, min($i, 8)) ?><?php endforeach; ?>
            </div>
        <?php elseif (!$steamId): ?>
            <div class="card k4x-empty"><?= $icon(K4ExtrasModel::uiIcon('mission_star'), 'k4x-icon-xl') ?><p><?= $L('k4x.no_active_missions') ?></p></div>
        <?php elseif (!$mine['daily'] && !$mine['weekly']): ?>
            <div class="card k4x-empty"><?= $icon(K4ExtrasModel::uiIcon('mission_star'), 'k4x-icon-xl') ?><p><?= $isSelf ? $L('k4x.no_missions_self') : $L('k4x.no_missions') ?></p></div>
        <?php else: ?>
            <div class="k4x-missions">
                <?php foreach ($mine['daily'] as $i => $m): ?><?= $card($m, $i) ?><?php endforeach; ?>
            </div>
            <?php if ($mine['weekly']): ?>
                <?php $w = $mine['weekly']; ?>
                <article class="k4x-weekly<?= $w['completed'] ? ' is-done' : '' ?>" style="--p:<?= (int) $w['percent'] ?>">
                    <div class="k4x-weekly-icon"><?= $icon($w['icon'], 'k4x-icon-xl') ?></div>
                    <div class="k4x-weekly-body">
                        <div class="k4x-weekly-row">
                            <span class="k4x-diff"><?= View::e($w['difficulty_label']) ?></span>
                            <span class="muted"><?= $L('k4x.resets_in') ?> <strong data-k4x-countdown="<?= (int) $countdowns['week'] ?>"></strong></span>
                        </div>
                        <h3><?= View::e($w['title']) ?></h3>
                        <div class="k4x-bar k4x-bar-lg"><span></span></div>
                        <div class="k4x-mission-foot">
                            <span><?= number_format((int) $w['progress']) ?> / <?= number_format((int) $w['target']) ?></span>
                            <span class="k4x-reward">+<?= number_format((int) $w['reward']) ?></span>
                        </div>
                    </div>
                </article>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($popular && $steamId): ?>
            <div class="k4x-section-head" style="margin-top:28px">
                <h2><?= $icon(K4ExtrasModel::uiIcon('stats')) ?><?= $L('k4x.today_on_server') ?></h2>
                <input type="search" class="k4x-find" data-k4x-find="#k4x-popular" placeholder="<?= $L('search.filter_ph') ?>" aria-label="<?= $L('common.search') ?>" autocomplete="off">
            </div>
            <div class="card k4x-list" id="k4x-popular">
                <?php foreach ($popular as $i => $p): ?>
                    <div class="k4x-row" data-k4x-text="<?= View::e(mb_strtolower($p['title'] . ' ' . $p['difficulty_label'])) ?>" style="--d:<?= $i * 50 ?>ms;--p:<?= (int) $p['rate'] ?>">
                        <?= $icon($p['icon']) ?>
                        <div class="k4x-row-main">
                            <span class="k4x-row-title"><?= View::e($p['title']) ?></span>
                            <div class="k4x-bar k4x-bar-sm"><span></span></div>
                        </div>
                        <span class="k4x-diff diff-<?= View::e(strtolower($p['difficulty'])) ?>"><?= View::e($p['difficulty_label']) ?></span>
                        <span class="k4x-row-num"><strong><?= (int) $p['done'] ?></strong>/<?= (int) $p['assigned'] ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="empty" data-k4x-find-empty hidden><?= $L('search.no_match') ?></div>
            </div>
        <?php endif; ?>
    </section>

    <aside class="k4x-side">
        <div class="k4x-kpis">
            <div class="k4x-kpi"><span><?= $L('k4x.active_today') ?></span><strong data-k4x-count="<?= (int) $overview['players'] ?>"><?= number_format((int) $overview['players']) ?></strong></div>
            <div class="k4x-kpi"><span><?= $L('k4x.missions_done') ?></span><strong data-k4x-count="<?= (int) $overview['completed'] ?>"><?= number_format((int) $overview['completed']) ?></strong></div>
            <div class="k4x-kpi"><span><?= $L('k4x.all_three') ?></span><strong data-k4x-count="<?= (int) $overview['perfect'] ?>"><?= number_format((int) $overview['perfect']) ?></strong></div>
            <div class="k4x-kpi"><span><?= $L('k4x.weekly_done') ?></span><strong data-k4x-count="<?= (int) $overview['weekly'] ?>"><?= number_format((int) $overview['weekly']) ?></strong></div>
        </div>

        <div class="card k4x-board">
            <div class="card-title"><?= $icon(K4ExtrasModel::uiIcon('hot_trend')) ?><?= $L('k4x.top_streaks') ?></div>
            <?php if (!$streaks): ?>
                <div class="muted k4x-board-empty"><?= $L('k4x.no_streaks') ?></div>
            <?php else: ?>
                <?php $sAv = Steam::avatars(array_column($streaks, 'steam_id')); ?>
                <?php foreach ($streaks as $i => $s): ?>
                    <a class="k4x-board-row" href="/k4/missions?player=<?= View::e((string) $s['steam_id']) ?>" style="--d:<?= $i * 40 ?>ms">
                        <span class="k4x-pos pos-<?= $i + 1 ?>"><?= $i + 1 ?></span>
                        <img src="<?= View::e($sAv[(string) $s['steam_id']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                        <span class="k4x-board-name"><?= View::e(Steam::displayName((string) $s['steam_id'], (string) $s['name'])) ?></span>
                        <span class="k4x-board-val"><?= (int) $s['streak'] ?> <?= $L('k4x.days_short') ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>
<?php require __DIR__ . '/_extras_script.php'; ?>
