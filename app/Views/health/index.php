<?php
use App\Core\View;
/** @var array $r */
$title = 'Panel health';
?>
<div class="auth-card" style="max-width:760px;text-align:left">
    <h2 style="margin-bottom:4px">Panel health</h2>
    <p class="muted" style="margin-top:0">v<?= View::e($r['version']) ?> · PHP <?= View::e($r['php']) ?></p>

    <h3 style="font-size:14px;margin:18px 0 6px">Database</h3>
    <?php if ($r['db']['ok']): ?>
        <p class="muted" style="margin:0">Connected · MySQL <?= View::e($r['db']['server']) ?></p>
    <?php else: ?>
        <p style="color:#ff6b5c;margin:0"><?= View::e($r['db']['error']) ?></p>
    <?php endif; ?>

    <?php if ($r['tables']): ?>
        <h3 style="font-size:14px;margin:18px 0 6px">Tables</h3>
        <div class="tag-flags">
            <?php foreach ($r['tables'] as $name => $exists): ?>
                <span class="badge <?= $exists ? 'badge-active' : 'badge-expired' ?>"><?= View::e($name) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($r['db']['ok']): ?>
        <h3 style="font-size:14px;margin:18px 0 6px">Migrations</h3>
        <p class="muted" style="margin:0 0 6px"><?= count($r['migrations']) ?> applied<?= $r['pending'] ? ', ' . count($r['pending']) . ' pending' : '' ?></p>
        <?php if ($r['pending']): ?>
            <div class="tag-flags"><?php foreach ($r['pending'] as $p): ?><span class="tag"><?= View::e($p) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
        <a class="btn btn-sm btn-primary" style="margin-top:10px" href="<?= View::e(url('/health?repair=1')) ?>">Run schema repair</a>
    <?php endif; ?>

    <h3 style="font-size:14px;margin:18px 0 6px">Last errors</h3>
    <?php if (!$r['errors']): ?>
        <p class="muted" style="margin:0">error.log is empty.</p>
    <?php else: ?>
        <div class="err-list">
            <?php foreach ($r['errors'] as $block): ?>
                <pre class="err-trace" style="margin:0"><?= View::e($block) ?></pre>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="margin-top:18px"><a class="btn btn-sm" href="<?= View::e(url('/')) ?>">Back to the panel</a></div>
</div>
