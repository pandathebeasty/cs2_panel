<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\View;
use App\Models\MonitorModel;
/** Monitor tab of the Servers page. @var array $monitor @var string $csrf */
$monServers = $monitor['servers'];
$range = $monitor['range'];
$L = fn(string $k) => View::e(Lang::t($k));

/** Area + line path for the player series inside a W x H box. */
$chart = static function (array $series, int $max): array {
    $w = 600; $h = 150; $n = count($series);
    $max = max(1, $max);
    $pts = [];
    foreach ($series as $i => $s) {
        if ($s['players'] === null) { $pts[] = null; continue; }
        $x = $n > 1 ? round($i * $w / ($n - 1), 1) : 0;
        $y = round($h - ($s['players'] / $max) * ($h - 8), 1);
        $pts[] = [$x, $y];
    }
    $line = ''; $area = ''; $run = [];
    $flush = static function () use (&$run, &$line, &$area, $h): void {
        if (!$run) return;
        $seg = 'M' . implode(' L', array_map(static fn($p) => $p[0] . ',' . $p[1], $run));
        $line .= $seg . ' ';
        $area .= $seg . ' L' . end($run)[0] . ',' . $h . ' L' . $run[0][0] . ',' . $h . ' Z ';
        $run = [];
    };
    foreach ($pts as $p) {
        if ($p === null) { $flush(); continue; }
        $run[] = $p;
    }
    $flush();
    return [trim($line), trim($area)];
};
?>
<div class="toolbar x-monitor-bar">
    <p class="muted" style="margin:0"><?= $L('monitor.sub') ?></p>
    <div class="x-monitor-controls">
        <div class="x-seg" role="tablist" aria-label="<?= $L('monitor.range') ?>">
            <?php foreach ($monitor['ranges'] as $r): ?>
                <a role="tab" aria-selected="<?= $r === $range ? 'true' : 'false' ?>" class="<?= $r === $range ? 'is-active' : '' ?>" href="<?= View::e(url('/servers?range=' . $r)) ?>#monitor"><?= View::e($r) ?></a>
            <?php endforeach; ?>
        </div>
        <?php if (Auth::isOwner()): ?>
            <form method="post" action="<?= View::e(url('/monitor/sample')) ?>">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <button class="btn btn-sm" type="submit"><?= $L('monitor.sample_now') ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!$monitor['enabled']): ?>
    <div class="x-callout x-callout-warn"><?= $L('monitor.disabled') ?></div>
<?php endif; ?>
<?php if ($monitor['error']): ?>
    <div class="x-callout x-callout-warn"><?= $L('monitor.error') ?></div>
<?php elseif (!$monServers): ?>
    <div class="card"><div class="x-empty x-empty-lg"><?= $L('servers.none') ?></div></div>
<?php endif; ?>

<div class="x-monitor-grid">
<?php foreach ($monServers as $s):
    $last = $s['last'];
    $max = max((int) ($last['max_players'] ?? 0), (int) ($s['range']['peak'] ?? 0), 1);
    [$line, $area] = $chart($s['series'], $max);
    $state = $s['status'];
    $gid = 'mg' . (int) $s['id'];
?>
    <article class="card x-monitor-card is-<?= View::e($state) ?>">
        <header class="x-monitor-head">
            <span class="x-pulse" aria-hidden="true"></span>
            <div class="x-monitor-name">
                <b title="<?= View::e($s['name']) ?>"><?= View::e($s['name']) ?></b>
                <span class="mono muted x-small"><?= View::e($s['address']) ?></span>
            </div>
            <span class="x-state"><?= $L('monitor.state_' . ($state === 'up' || $state === 'down' ? $state : 'unknown')) ?></span>
        </header>
        <div class="x-monitor-kpis">
            <div><span><?= $L('monitor.uptime') ?></span><b><?= $s['range']['uptime'] !== null ? View::e((string) $s['range']['uptime']) . '%' : '—' ?></b></div>
            <div><span><?= $L('monitor.avg_players') ?></span><b><?= $s['range']['avg'] !== null ? View::e((string) $s['range']['avg']) : '—' ?></b></div>
            <div><span><?= $L('monitor.peak') ?></span><b><?= $s['range']['peak'] !== null ? (int) $s['range']['peak'] : '—' ?></b></div>
            <div><span><?= $L('servers.players') ?></span><b><?= $last && (int) $last['online'] ? (int) $last['players'] . '/' . (int) $last['max_players'] : '—' ?></b></div>
        </div>
        <svg class="x-chart" viewBox="0 0 600 150" preserveAspectRatio="none" role="img" aria-label="<?= $L('monitor.chart_label') ?>">
            <defs><linearGradient id="<?= $gid ?>" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="currentColor" stop-opacity=".35"/><stop offset="1" stop-color="currentColor" stop-opacity="0"/></linearGradient></defs>
            <line x1="0" y1="75" x2="600" y2="75" class="x-chart-grid"/>
            <?php if ($area !== ''): ?>
                <path d="<?= $area ?>" fill="url(#<?= $gid ?>)"/>
                <path d="<?= $line ?>" class="x-chart-line" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" pathLength="1"/>
            <?php else: ?>
                <text x="300" y="80" text-anchor="middle" class="x-chart-empty"><?= $L('monitor.no_data') ?></text>
            <?php endif; ?>
        </svg>
        <div class="x-uptime-strip" aria-hidden="true">
            <?php foreach ($s['series'] as $b): ?>
                <i class="<?= $b['online'] === null ? 'is-none' : ($b['online'] >= 0.99 ? 'is-up' : ($b['online'] > 0 ? 'is-partial' : 'is-down')) ?>" title="<?= View::e(date('d.m H:i', $b['t'])) ?>"></i>
            <?php endforeach; ?>
        </div>
        <footer class="x-monitor-foot muted x-small">
            <span><?= $last ? View::e($last['map'] ?: '—') : '—' ?></span>
            <?php if ($s['changed_at'] > 0): ?>
                <span><?= View::e(Lang::t($state === 'down' ? 'monitor.down_for' : 'monitor.up_for', ['t' => MonitorModel::duration(time() - $s['changed_at'])])) ?></span>
            <?php endif; ?>
        </footer>
    </article>
<?php endforeach; ?>
</div>
