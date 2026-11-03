<?php
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Steam;
use App\Core\View;
/**
 * @var string $steamId @var string $name @var string $avatar @var array $avatars @var ?array $admin
 * @var array $bans @var array $mutes @var array $warns @var array $vip @var array $alts @var array $ips
 * @var bool $altsAvailable @var array $audit @var int $activeBans @var int $activeMutes @var int $activeWarns
 */
$title = ($name !== '' ? $name : $steamId) . ' · ' . Lang::t('player.title');
$L = fn(string $k) => View::e(Lang::t($k));
$status = static fn(array $r): string => '<span class="badge badge-' . View::e(strtolower((string) ($r['status'] ?? ''))) . '">' . View::e((string) ($r['status'] ?? '')) . '</span>';
$expires = static function (array $r) use ($L): string {
    if ((int) ($r['duration'] ?? 0) === 0 && strtoupper((string) ($r['status'] ?? '')) === 'ACTIVE') {
        return '<span class="badge badge-perm">' . $L('common.permanent') . '</span>';
    }
    $ends = (string) ($r['ends'] ?? '');
    return View::e($ends !== '' ? $ends : '—');
};
$sections = [
    'bans'  => [Lang::t('nav.bans'), $bans],
    'mutes' => [Lang::t('nav.mutes'), $mutes],
    'warns' => [Lang::t('nav.warns'), $warns],
];
?>
<section class="x-player-hero">
    <img class="x-player-avatar" src="<?= View::e($avatar) ?>" alt="" width="96" height="96">
    <div class="x-player-id">
        <span class="x-eyebrow"><?= $L('player.title') ?></span>
        <h1 class="x-page-title"><?= View::e($name !== '' ? $name : $steamId) ?></h1>
        <?php if ($name !== ''): ?><span class="mono x-player-sid" data-steamid="<?= View::e($steamId) ?>"><?= View::e($steamId) ?></span><?php endif; ?>
        <div class="x-player-meta">
            <a class="btn btn-sm btn-ghost" href="<?= View::e(Steam::profileUrl($steamId)) ?>" target="_blank" rel="noopener"><?= $L('player.open_steam') ?> ↗</a>
            <?php if ($admin): $ag = trim((string) ($admin['group_name'] ?? '')); ?><span class="badge badge-active"><?= $L('player.is_admin') ?><?= $ag !== '' ? ' · ' . View::e($ag) : '' ?></span><?php endif; ?>
            <?php if ($vip): $vg = implode(', ', array_unique(array_filter(array_map(static fn($v) => trim((string) ($v['group'] ?? '')), $vip)))); ?><span class="badge badge-perm">VIP<?= $vg !== '' && strcasecmp($vg, 'VIP') !== 0 ? ' · ' . View::e($vg) : '' ?></span><?php endif; ?>
            <?php if ($activeBans): ?><span class="badge x-badge-danger"><?= $L('player.banned_now') ?></span><?php endif; ?>
        </div>
    </div>
    <div class="x-player-stats">
        <div class="x-stat"><b><?= $activeBans ?></b><span><?= $L('player.active_bans') ?></span></div>
        <div class="x-stat"><b><?= $activeMutes ?></b><span><?= $L('player.active_mutes') ?></span></div>
        <div class="x-stat"><b><?= $activeWarns ?></b><span><?= $L('player.active_warns') ?></span></div>
        <div class="x-stat"><b><?= count($alts) ?></b><span><?= $L('player.alts') ?></span></div>
    </div>
</section>

<div class="x-player-grid">
    <div class="x-player-main">
        <?php foreach ($sections as [$label, $rows]): ?>
            <div class="card x-section">
                <div class="card-title"><?= View::e($label) ?> <span class="x-count"><?= count($rows) ?></span></div>
                <?php if (!$rows): ?>
                    <div class="x-empty"><?= $L('player.none') ?></div>
                <?php else: ?>
                    <div class="table-wrap"><table class="x-stack">
                        <thead><tr><th>#</th><th><?= $L('common.reason') ?></th><th><?= $L('common.admin') ?></th><th><?= $L('player.created') ?></th><th><?= $L('common.expires') ?></th><th><?= $L('common.status') ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td data-label="#" class="mono">#<?= (int) $r['id'] ?><?= isset($r['type']) ? ' <span class="tag">' . View::e((string) $r['type']) . '</span>' : '' ?></td>
                                <td data-label="<?= $L('common.reason') ?>"><?= View::e((string) ($r['reason'] ?? '')) ?></td>
                                <td data-label="<?= $L('common.admin') ?>"><?= View::e((string) ($r['admin_name'] ?? '—')) ?></td>
                                <td data-label="<?= $L('player.created') ?>" class="muted"><?= View::e((string) ($r['created'] ?? '')) ?></td>
                                <td data-label="<?= $L('common.expires') ?>" class="muted"><?= $expires($r) ?></td>
                                <td data-label="<?= $L('common.status') ?>"><?= $status($r) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="card x-section">
            <div class="card-title x-title-row"><?= $L('player.audit') ?><?php if (count($audit) >= 25 && \App\Core\Auth::canManage()): ?> <a class="btn btn-sm btn-ghost" href="<?= View::e(url('/logs?search=' . $steamId)) ?>"><?= $L('player.audit_all') ?> →</a><?php endif; ?></div>
            <?php if (!$audit): ?>
                <div class="x-empty"><?= $L('player.none') ?></div>
            <?php else: ?>
                <ol class="x-timeline">
                    <?php foreach ($audit as $a): ?>
                        <li>
                            <span class="x-timeline-dot" aria-hidden="true"></span>
                            <div>
                                <b><?= View::e(Logger::actionLabel((string) $a['action'])) ?></b>
                                <span class="muted"> · <?= View::e((string) ($a['actor_name'] ?? '—')) ?></span>
                                <?php if (!empty($a['details'])): ?><div class="muted x-small"><?= View::e((string) $a['details']) ?></div><?php endif; ?>
                            </div>
                            <time class="muted x-small"><?= View::e((string) $a['created_at']) ?></time>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>

    <aside class="x-player-side">
        <div class="card x-section">
            <div class="card-title"><?= $L('player.alts') ?></div>
            <?php if (!$altsAvailable): ?>
                <p class="muted x-small"><?= $L('player.alts_unavailable') ?></p>
            <?php elseif (!$alts): ?>
                <div class="x-empty"><?= $L('player.no_alts') ?></div>
            <?php else: ?>
                <ul class="x-alt-list">
                    <?php foreach ($alts as $alt): ?>
                        <li class="<?= $alt['banned'] ? 'is-banned' : '' ?>">
                            <img src="<?= View::e($avatars[$alt['steamid']] ?? Steam::avatarPlaceholder()) ?>" alt="" width="34" height="34" loading="lazy">
                            <div>
                                <a <?= Steam::linkAttrs($alt['steamid']) ?>><?= View::e((string) ($alt['name'] ?: $alt['steamid'])) ?></a>
                                <div class="muted x-small mono"><?= View::e((string) $alt['shared']) ?></div>
                            </div>
                            <?php if ($alt['banned']): ?><span class="badge x-badge-danger"><?= $L('player.banned') ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="card x-section">
            <div class="card-title"><?= $L('player.ips') ?></div>
            <?php if (!$ips): ?>
                <div class="x-empty"><?= $L('player.none') ?></div>
            <?php else: ?>
                <ul class="x-ip-list mono">
                    <?php foreach ($ips as $ip): ?>
                        <li><span><?= View::e($ip['address']) ?></span><span class="muted x-small"><?= View::e((string) ($ip['used_at'] ?? '')) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php if ($vip): ?>
            <div class="card x-section">
                <div class="card-title">VIP</div>
                <ul class="x-ip-list">
                    <?php foreach ($vip as $v): ?>
                        <li><span><?= View::e((string) ($v['group'] ?? '')) ?></span><span class="muted x-small"><?= (int) ($v['expires'] ?? 0) === 0 ? $L('common.permanent') : View::e(date('Y-m-d H:i', (int) $v['expires'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </aside>
</div>
