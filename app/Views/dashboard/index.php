<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var array $banStats @var array $muteStats @var array $warnStats @var array $vipStats @var int $serverCount @var array $topPlayers @var array $recentBans @var array $recentMutes */
$title = Lang::t('nav.dashboard');
// Rank display style (premier vs competitive badges), matching the Stats page.
$rankType = \App\Models\StatsBackend::isK4System()
    ? (string) \App\Core\Settings::get('k4_rank_type', 'competitive')
    : 'competitive';

// Localized "x ago" helper for dates coming from the DB.
$ago = function (?string $dt): string {
    if (!$dt) {
        return '—';
    }
    $ts = is_numeric($dt) ? (int) $dt : strtotime((string) $dt);
    if ($ts === false || $ts <= 0) {
        return View::e((string) $dt);
    }
    $diff = max(0, time() - $ts);
    $units = [
        [31536000, 'time.year', 'time.years'],
        [2592000,  'time.month', 'time.months'],
        [604800,   'time.week', 'time.weeks'],
        [86400,    'time.day', 'time.days'],
        [3600,     'time.hour', 'time.hours'],
        [60,       'time.minute', 'time.minutes'],
    ];
    foreach ($units as [$secs, $one, $many]) {
        if ($diff >= $secs) {
            $n = (int) floor($diff / $secs);
            $unit = Lang::t($n > 1 ? $many : $one);
            return Lang::t('time.ago', ['n' => $n, 'unit' => $unit]);
        }
    }
    return Lang::t('time.just_now');
};

// Expiry cell: Permanent badge, or the end date (unban/unmute time once lifted).
$isLifted = static fn(array $row): bool => in_array(strtoupper((string) ($row['status'] ?? '')), ['UNBANNED', 'UNMUTED'], true);
$expires = function (array $row) use ($isLifted): string {
    if ((int) ($row['duration'] ?? 0) === 0 && !$isLifted($row)) {
        return '<span class="badge badge-perm">' . View::e(Lang::t('common.permanent')) . '</span>';
    }
    return '<span class="muted">' . View::e($row['ends'] ?: '—') . '</span>';
};
$tt = fn(string $k) => View::e(Lang::t($k));
$statsOn = \App\Models\StatsBackend::enabled();
$statsBase = \App\Models\StatsBackend::isK4System() ? '/k4' : '/zenith';
$currentPlayerRank = $currentPlayerRank ?? null;
$showPersonalRank = \App\Core\Auth::hasSteam() && $currentPlayerRank !== null;
$panelName = (string) \App\Core\Settings::get('site_name', 'CS2 Panel');
$heroServers = $heroServers ?? [];
$firstServer = $heroServers[0] ?? null;
$discordInvite = trim((string) \App\Core\Settings::get('discord_invite', ''));
$discordUrl = $discordInvite !== ''
    ? (str_starts_with($discordInvite, 'http') ? $discordInvite : 'https://discord.gg/' . ltrim($discordInvite, '/'))
    : '';
$forumUrl = trim((string) \App\Core\Settings::get('forum_url', ''));
$heroCounts = array_map(static fn(array $hour): int => (int) $hour['count'], $activityHours ?? []);
$heroPeak = max(1, ...($heroCounts ?: [0]));
$heroTrace = [];
foreach ($heroCounts as $index => $count) {
    $heroTrace[] = ($index * 100 / max(1, count($heroCounts) - 1)) . ',' . (42 - $count * 35 / $heroPeak);
}
?>
<?php if (!empty($update)): ?>
<!-- Dismissible update info bar (root only). Auto-hides after ~12s. -->
<div class="update-bar" id="update-bar" role="status">
    <svg class="update-bar-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
    <span class="update-bar-text"><?= Lang::t('update.found', ['v' => 'v' . View::e($update['version'])]) ?></span>
    <a class="update-bar-link" href="<?= View::e($update['url']) ?>" target="_blank" rel="noopener"><?= $tt('update.goto_git') ?></a>
    <button type="button" class="update-bar-close" id="update-bar-close" aria-label="Close">&times;</button>
</div>
<script>
(function () {
    var bar = document.getElementById('update-bar');
    if (!bar) return;
    var closed = false;
    function dismiss() {
        if (closed) return; closed = true;
        bar.classList.add('hiding');
        setTimeout(function () { if (bar.parentNode) bar.remove(); }, 300);
    }
    document.getElementById('update-bar-close').addEventListener('click', dismiss);
    setTimeout(dismiss, 12000); // auto-hide after 12s
})();
</script>
<?php endif; ?>
<section class="portal-hero panel-live-hero<?= $showPersonalRank ? '' : ' panel-hero-no-rank' ?>" data-portal-hero data-dashboard-hero
         data-online-label="<?= $tt('servers.online') ?>" data-loading-label="<?= $tt('portal.server_loading') ?>"
         data-error-label="<?= $tt('portal.server_unavailable') ?>" aria-labelledby="dashboard-overview-title">
    <div class="panel-server-viewport" data-server-viewport tabindex="0" role="region" aria-label="<?= $tt('portal.server_carousel') ?>">
        <?php foreach ($heroServers ?: [null] as $index => $server): ?>
        <div class="panel-server-slide" data-server-slide
             data-info-url="<?= View::e($server['info_url'] ?? '') ?>"
             data-connect-url="<?= View::e($server['connect_url'] ?? '') ?>"
             data-server-name="<?= View::e($server['hostname'] ?? $panelName) ?>"
             aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>">
            <img class="panel-server-map" data-server-map alt="" hidden>
            <div class="panel-server-terrain" aria-hidden="true"></div>
            <div class="panel-server-hud" aria-hidden="true">
                <div class="panel-hud-card panel-hud-players">
                    <span class="panel-hud-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="9" cy="8" r="3"/><path d="M3 20v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 5"/></svg></span>
                    <div><span><?= $tt('servers.players') ?></span><strong data-server-players>&mdash;</strong></div>
                </div>
                <div class="panel-hud-card panel-hud-map">
                    <span class="panel-hud-reticle"></span>
                    <div><span><?= $tt('servers.map') ?></span><strong data-server-map-name>&mdash;</strong></div>
                </div>
                <div class="panel-hud-card panel-hud-chart">
                    <div class="panel-hud-chart-head"><span><?= $tt('portal.community_activity') ?></span><strong><?= number_format(array_sum($heroCounts)) ?></strong></div>
                    <svg class="panel-hud-sparkline" viewBox="0 0 100 48" preserveAspectRatio="none">
                        <?php if ($heroTrace): ?>
                        <polyline points="<?= View::e(implode(' ', $heroTrace)) ?>" fill="none" stroke="currentColor" stroke-width="1.2" vector-effect="non-scaling-stroke"/>
                        <?php endif; ?>
                    </svg>
                    <div class="panel-hud-bars">
                        <?php foreach ($heroCounts as $count): ?><i style="--bar-height:<?= max(5, $count * 100 / $heroPeak) ?>%"></i><?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="portal-hero-grid" aria-hidden="true"></div>
    <div class="portal-hero-copy">
        <span class="portal-kicker"><span class="portal-signal" aria-hidden="true"></span> <?= View::e($panelName) ?> <span aria-hidden="true">/</span> <?= $tt('portal.community') ?></span>
        <div class="panel-live-chips">
            <span class="panel-live-status" data-server-status role="status"><?= $firstServer ? $tt('portal.server_loading') : $tt('servers.none') ?></span>
            <span class="panel-live-map"><?= $tt('servers.map') ?>: <strong data-current-map>&mdash;</strong></span>
        </div>
        <h1 id="dashboard-overview-title"><?= View::e($panelName) ?></h1>
        <div class="panel-current-server" data-current-server><?= View::e($firstServer['hostname'] ?? '') ?></div>
        <div class="portal-hero-actions">
            <a class="btn btn-primary portal-cta" data-server-connect
               <?= !empty($firstServer['connect_url']) ? 'href="' . View::e($firstServer['connect_url']) . '"' : 'aria-disabled="true" tabindex="-1"' ?>>
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M7 4v16l14-8z"/></svg><?= $tt('servers.connect') ?>
            </a>
            <a class="btn panel-leaderboard-link" href="<?= View::e($statsBase . '/ranks') ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 3h8v6a4 4 0 0 1-8 0V3zM8 5H4v3a4 4 0 0 0 4 4M16 5h4v3a4 4 0 0 1-4 4M12 13v5M8 21h8M9 18h6"/></svg><?= $tt('portal.leaderboard') ?>
            </a>
            <?php if ($discordUrl !== ''): ?>
            <a class="btn panel-hero-social" href="<?= View::e($discordUrl) ?>" target="_blank" rel="noopener noreferrer" title="Discord" aria-label="Discord">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M20 4.4A19 19 0 0015.5 3l-.3.5a14 14 0 014 2 16 16 0 00-13.5 0 14 14 0 014-2L9.5 3A19 19 0 004 4.4 20 20 0 002 18.6 19 19 0 007.7 21l.4-.6a12 12 0 01-2-1l.5-.3a13 13 0 0011 0l.5.3a12 12 0 01-2 1l.4.6A19 19 0 0022 18.6 20 20 0 0020 4.4zM9 15a1.7 1.7 0 010-3.4A1.7 1.7 0 019 15zm6 0a1.7 1.7 0 010-3.4A1.7 1.7 0 0115 15z"/></svg>
            </a>
            <?php endif; ?>
            <?php if ($forumUrl !== ''): ?>
            <a class="btn panel-hero-social" href="<?= View::e($forumUrl) ?>" target="_blank" rel="noopener noreferrer" title="Forum" aria-label="Forum">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($showPersonalRank): ?>
    <aside class="portal-hero-note panel-rank-spotlight">
        <span class="portal-kicker"><?= $tt('nav.ranks') ?></span>
        <div class="panel-rank-emblem"><?= \App\Core\RankIcon::html((string) ($currentPlayerRank['rank_name'] ?? ''), $rankType, (int) ($currentPlayerRank['points'] ?? 0)) ?></div>
        <strong class="panel-ranked-name" title="<?= View::e(\App\Core\Auth::user()['name'] ?? '') ?>"><?= View::e(\App\Core\Auth::user()['name'] ?? '') ?></strong>
        <div class="panel-rank-score"><strong data-portal-count="<?= (int) ($currentPlayerRank['points'] ?? 0) ?>"><?= number_format((int) ($currentPlayerRank['points'] ?? 0)) ?></strong><span><?= $tt('dash.points') ?></span></div>
        <?php if ($statsOn): ?>
            <a class="portal-text-link" href="<?= View::e($statsBase . '/ranks') ?>"><?= $tt('portal.open_ranks') ?><span aria-hidden="true">&rarr;</span></a>
        <?php elseif (\App\Core\Auth::canManage()): ?>
            <a class="portal-text-link" href="/settings#integrations"><?= $tt('nav.settings') ?><span aria-hidden="true">&rarr;</span></a>
        <?php endif; ?>
    </aside>
    <?php endif; ?>
    <?php if (count($heroServers) > 1): ?>
    <div class="panel-server-dots" role="group" aria-label="<?= $tt('nav.servers') ?>">
        <?php foreach ($heroServers as $index => $server): ?>
        <button type="button" data-server-dot="<?= $index ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>"
                aria-label="<?= View::e(Lang::t('portal.server_slide', ['n' => $index + 1, 'name' => $server['hostname']])) ?>" title="<?= View::e($server['hostname']) ?>"></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="portal-hero-footer" aria-hidden="true"><span><?= View::e($panelName) ?> / <?= $tt('portal.community') ?></span><span><?= $tt('portal.scroll_hint') ?> &darr;</span></div>
</section>
<script defer src="/assets/js/dashboard-hero.js?v=<?= View::e(\App\Core\Version::current() . '.' . filemtime(PUBLIC_PATH . '/assets/js/dashboard-hero.js')) ?>"></script>
<div class="portal-section-heading">
    <span class="portal-section-number" aria-hidden="true">01</span>
    <div><span class="portal-kicker"><?= $tt('portal.your_network') ?></span><h2><?= $tt('portal.overview') ?></h2></div>
</div>
<div class="grid stats-grid dashboard-stats" style="margin-bottom:22px">
    <div class="card stat accent">
        <div class="value"><?= number_format($banStats['active']) ?></div>
        <div class="label"><?= $tt('dash.active_bans') ?></div>
    </div>
    <div class="card stat">
        <div class="value"><?= number_format($banStats['total']) ?></div>
        <div class="label"><?= $tt('dash.total_bans') ?></div>
    </div>
    <div class="card stat orange">
        <div class="value"><?= number_format($muteStats['active']) ?></div>
        <div class="label"><?= $tt('dash.active_mutes') ?></div>
    </div>
    <div class="card stat">
        <div class="value"><?= number_format($warnStats['active']) ?></div>
        <div class="label"><?= $tt('dash.active_warns') ?></div>
    </div>
    <div class="card stat purple">
        <div class="value"><?= number_format($vipStats['active']) ?></div>
        <div class="label"><?= $tt('dash.active_vips') ?></div>
    </div>
    <div class="card stat green">
        <div class="value"><?= number_format($serverCount) ?></div>
        <div class="label"><?= $tt('dash.servers') ?></div>
    </div>
</div>

<!-- Hourly activity graph + recent players (above Top players) -->
<?php
$recentActive  = $recentActive ?? [];
$activityHours = array_values($activityHours ?? []);
$activeAvatars = $recentActive ? Steam::avatars(array_column($recentActive, 'steam_id')) : [];

$n = count($activityHours);
$counts = array_map(static fn(array $hour): int => (int) $hour['count'], $activityHours);
$maxCount = $counts ? max($counts) : 0;
$totalActive6h = array_sum($counts);
$peakIndex = $maxCount > 0 ? array_search($maxCount, $counts, true) : null;
$latestHour = $n > 0 ? $activityHours[$n - 1] : null;
$yMax = max(1, $maxCount);
$pts = [];
foreach ($activityHours as $i => $hour) {
    $pts[] = [round(($i + .5) * 100 / $n, 2), round(94 - (int) $hour['count'] * 82 / $yMax, 2)];
}
$linePath = '';
foreach ($pts as $i => [$x, $y]) {
    if ($i === 0) {
        $linePath = "M{$x} {$y}";
    } else {
        [$previousX, $previousY] = $pts[$i - 1];
        $middleX = round(($previousX + $x) / 2, 2);
        $linePath .= " C{$middleX} {$previousY}, {$middleX} {$y}, {$x} {$y}";
    }
}
$areaPath = $linePath !== '' ? $linePath . ' L' . $pts[$n - 1][0] . ' 94 L' . $pts[0][0] . ' 94 Z' : '';
?>
<div class="portal-section-heading">
    <span class="portal-section-number" aria-hidden="true">02</span>
    <div><span class="portal-kicker"><?= $tt('nav.stats') ?></span><h2><?= $tt('portal.community_activity') ?></h2></div>
</div>
<div class="grid dash-two portal-activity-layout" style="margin-bottom:22px">
    <div class="card activity-card panel-activity-card">
        <div class="panel-activity-header">
            <div class="panel-activity-total">
                <span class="portal-kicker"><?= $tt('dash.activity_total') ?></span>
                <strong><?= number_format($totalActive6h) ?></strong>
            </div>
            <div class="panel-activity-highlights">
                <div>
                    <span><?= $tt('dash.activity_peak') ?></span>
                    <strong><?= $peakIndex !== null ? View::e($activityHours[$peakIndex]['label']) : '&mdash;' ?></strong>
                    <small><?= number_format($maxCount) ?></small>
                </div>
                <div>
                    <span><?= $tt('dash.activity_latest') ?></span>
                    <strong><?= $latestHour !== null ? number_format((int) $latestHour['count']) : '&mdash;' ?></strong>
                    <small><?= View::e($latestHour['label'] ?? '—') ?></small>
                </div>
            </div>
        </div>
        <div class="activity-chart panel-activity-chart" data-panel-chart role="group" aria-label="<?= $tt('portal.chart_label') ?>">
            <div class="panel-activity-plot">
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="panel-activity-trend" aria-hidden="true">
                    <defs>
                        <linearGradient id="actFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="var(--portal-accent)" stop-opacity="0.18"/>
                            <stop offset="1" stop-color="var(--portal-accent)" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <?php foreach ([12, 39, 66, 94] as $y): ?>
                        <line x1="0" y1="<?= $y ?>" x2="100" y2="<?= $y ?>" class="activity-grid" vector-effect="non-scaling-stroke"/>
                    <?php endforeach; ?>
                    <?php if ($areaPath !== '' && $maxCount > 0): ?><path class="panel-chart-area" d="<?= $areaPath ?>" fill="url(#actFill)"/><?php endif; ?>
                    <?php if ($linePath !== '' && $maxCount > 0): ?><path class="panel-chart-line" pathLength="1" d="<?= trim($linePath) ?>" fill="none" stroke="var(--portal-accent)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/><?php endif; ?>
                </svg>
                <div class="panel-activity-columns" style="--activity-columns:<?= max(1, $n) ?>">
                    <?php foreach ($activityHours as $i => $hour): ?>
                    <button type="button" class="panel-activity-column<?= $i === $peakIndex ? ' is-peak' : '' ?>"
                            style="--bar-height:<?= round((int) $hour['count'] * 100 / $yMax, 2) ?>%;--point-y:<?= $pts[$i][1] ?>%;--bar-delay:<?= $i * .08 ?>s"
                            aria-label="<?= View::e(Lang::t('dash.activity_hour_detail', ['hour' => $hour['label'], 'count' => number_format((int) $hour['count'])])) ?>">
                        <span class="panel-activity-track" aria-hidden="true"><i class="panel-activity-fill"></i></span>
                        <span class="panel-activity-value" aria-hidden="true"><?= number_format((int) $hour['count']) ?></span>
                        <span class="panel-activity-tooltip" aria-hidden="true"><small><?= View::e($hour['label']) ?></small><strong><?= number_format((int) $hour['count']) ?></strong><span><?= $tt('dash.activity_total') ?></span></span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php if ($maxCount === 0): ?><div class="panel-activity-empty"><?= $tt('dash.no_active') ?></div><?php endif; ?>
            </div>
            <div class="panel-activity-hours" aria-hidden="true" style="--activity-columns:<?= max(1, $n) ?>">
                <?php foreach ($activityHours as $hour): ?><span><?= View::e($hour['label']) ?></span><?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card portal-recent-players panel-activity-recent">
        <div class="panel-activity-recent-head"><div class="card-title"><?= $tt('dash.recent_active') ?></div><span class="panel-recent-count"><?= count($recentActive) ?></span></div>
        <?php if (!$recentActive): ?>
            <div class="empty"><?= $tt('dash.no_active') ?></div>
        <?php else: ?>
            <ul class="panel-recent-list">
                <?php foreach ($recentActive as $p): ?>
                <li>
                    <a <?= Steam::linkAttrs($p['steam_id']) ?> class="panel-recent-player">
                        <img class="player-avatar" src="<?= View::e($activeAvatars[(string) $p['steam_id']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                        <span class="panel-recent-info"><strong class="pname" title="<?= View::e($p['name'] ?: 'Player') ?>"><?= View::e($p['name'] ?: 'Player') ?></strong><span><span class="panel-recent-marker" aria-hidden="true"></span><?= $tt('dash.last_seen') ?> <span><?= View::e($ago($p['last_online'] ?? null)) ?></span></span></span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M7 17L17 7M7 7h10v10"/></svg>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<!-- Top players (K4-Zenith) -->
<div class="portal-section-heading panel-ranks-heading">
    <span class="portal-section-number" aria-hidden="true">03</span>
    <div><span class="portal-kicker"><?= $tt('nav.stats') ?></span><h2><?= $tt('nav.ranks') ?></h2></div>
    <?php if ($statsOn): ?><a class="portal-text-link" href="<?= View::e($statsBase . '/ranks') ?>"><?= $tt('portal.open_ranks') ?><span aria-hidden="true">&nearr;</span></a><?php endif; ?>
</div>
<?php if ($topPlayers): ?>
<?php $avatars = Steam::avatars(array_column($topPlayers, 'steam_id')); ?>
<div class="card panel-ranking-card" style="margin-bottom:22px">
    <div class="card-title"><?= $tt('dash.top_players') ?></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th class="hide-mobile">#</th><th><?= $tt('dash.player') ?></th><th><?= $tt('dash.points') ?></th><th class="panel-rank-cell"><?= $tt('dash.rank') ?></th><th class="hide-mobile"><?= $tt('dash.kills') ?></th><th class="hide-mobile"><?= $tt('dash.deaths') ?></th><th class="hide-mobile"><?= $tt('dash.last_seen') ?></th></tr></thead>
            <tbody>
            <?php foreach ($topPlayers as $i => $p): ?>
                <tr>
                    <td class="muted hide-mobile"><span class="panel-rank-position"><?= $i + 1 ?></span></td>
                    <td>
                        <a <?= Steam::linkAttrs($p['steam_id']) ?> class="player-cell">
                            <img class="player-avatar" src="<?= View::e($avatars[(string) $p['steam_id']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                            <span class="pname"><?= View::e($p['name'] ?: 'Player') ?></span>
                        </a>
                    </td>
                    <td><?= \App\Core\RankIcon::html('', 'premier', (int) ($p['points'] ?? 0)) ?></td>
                    <td class="panel-rank-cell">
                        <span class="rank-cell">
                            <?= \App\Core\RankIcon::html((string) ($p['rank_name'] ?? ''), 'competitive', (int) ($p['points'] ?? 0)) ?>
                            <span class="rank-name"><?= View::e(\App\Core\RankIcon::cleanName((string) ($p['rank_name'] ?? ''))) ?></span>
                        </span>
                    </td>
                    <td class="hide-mobile" style="color:var(--green);font-weight:600"><?= number_format((int) $p['kills']) ?></td>
                    <td class="muted hide-mobile"><?= number_format((int) $p['deaths']) ?></td>
                    <td class="muted hide-mobile"><?= View::e($ago($p['last_online'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card panel-ranking-card"><div class="empty"><?= $tt($statsOn ? 'portal.rank_empty' : 'portal.rank_unavailable') ?></div></div>
<?php endif; ?>

<!-- Recent bans + Recent mutes, side by side -->
<?php
                                                                          
$recentIds = [];
foreach (array_merge($recentBans, $recentMutes) as $r) {
    if (!empty($r['player_steamid'])) {
        $recentIds[] = (string) $r['player_steamid'];
    }
}
$recentAvatars = $recentIds ? Steam::avatars(array_values(array_unique($recentIds))) : [];

                                                                                        
$playerCell = function (array $row) use ($recentAvatars): string {
    $name = View::e($row['player_name'] ?: '—');
    $sid  = (string) ($row['player_steamid'] ?? '');
    $img  = $recentAvatars[$sid] ?? Steam::avatarPlaceholder();
    $avatar = '<img class="player-avatar" src="' . View::e($img) . '" alt="" loading="lazy">';
    if ($sid !== '') {
        return '<a ' . Steam::linkAttrs($sid) . ' class="player-cell">'
            . $avatar . '<span class="pname">' . $name . '</span></a>';
    }
    return '<span class="player-cell">' . $avatar . '<span class="pname">' . $name . '</span></span>';
};
?>
<div class="portal-section-heading">
    <span class="portal-section-number" aria-hidden="true">04</span>
    <div><span class="portal-kicker"><?= $tt('nav.moderation') ?></span><h2><?= $tt('portal.moderation_feed') ?></h2></div>
</div>
<div class="grid dash-two portal-moderation-layout">
    <div class="card">
        <div class="panel-card-header">
            <div class="card-title"><?= $tt('dash.recent_bans') ?></div>
            <?php if ($recentBans): ?><a class="portal-text-link" href="/bans"><?= $tt('portal.view_bans') ?><span aria-hidden="true">&nearr;</span></a><?php endif; ?>
        </div>
        <?php if (!$recentBans): ?>
            <div class="empty"><?= $tt('dash.no_bans') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th><?= $tt('dash.player') ?></th><th><?= $tt('dash.admin') ?></th><th class="hide-mobile"><?= $tt('dash.added') ?></th><th><?= $tt('dash.expires') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($recentBans as $b): ?>
                        <tr>
                            <td><?= $playerCell($b) ?></td>
                            <td class="muted"><?= View::e($b['admin_name'] ?: 'Console') ?></td>
                            <td class="muted hide-mobile"><?= View::e($ago($b['created'] ?? null)) ?></td>
                            <td><?= $expires($b) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="panel-card-header">
            <div class="card-title"><?= $tt('dash.recent_mutes') ?></div>
            <?php if ($recentMutes): ?><a class="portal-text-link" href="/mutes"><?= $tt('portal.view_mutes') ?><span aria-hidden="true">&nearr;</span></a><?php endif; ?>
        </div>
        <?php if (!$recentMutes): ?>
            <div class="empty"><?= $tt('dash.no_mutes') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th><?= $tt('dash.player') ?></th><th><?= $tt('dash.admin') ?></th><th class="hide-mobile"><?= $tt('dash.added') ?></th><th><?= $tt('dash.expires') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($recentMutes as $m): ?>
                        <tr>
                            <td><?= $playerCell($m) ?></td>
                            <td class="muted"><?= View::e($m['admin_name'] ?: 'Console') ?></td>
                            <td class="muted hide-mobile"><?= View::e($ago($m['created'] ?? null)) ?></td>
                            <td><?php if ((int) ($m['duration'] ?? 0) === 0 && !$isLifted($m)): ?><span class="badge badge-perm"><?= $tt('common.permanent') ?></span><?php else: ?><span class="muted"><?= View::e($m['ends'] ?: '—') ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
