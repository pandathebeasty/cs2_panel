<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Steam;
use App\Core\View;
/** @var \App\Core\Paginator $logs @var array $filters @var array $actions @var array $avatars @var array $names @var ?array $errorLog @var string $csrf */
$title = Lang::t('logs.title');
$pg = $logs;
$liveSearchKey = 'logs';
$avatars = $avatars ?? [];
$names = $names ?? [];

$label = static fn(string $action): string => Logger::actionLabel($action);

// Map action -> badge color.
$badge = function (string $a): string {
    if (str_contains($a, 'delete') || str_contains($a, 'remove')) return 'badge-silence';
    if (str_contains($a, 'ban') && !str_contains($a, 'unban')) return 'badge-perm';
    if (str_contains($a, 'create') || str_contains($a, 'add')) return 'badge-active';
    if (str_contains($a, 'reset')) return 'badge-expired';
    return 'badge-role-admin';
};

// Rough severity styling for an error log block.
$errClass = function (string $block): string {
    if (stripos($block, 'fatal') !== false || stripos($block, 'uncaught') !== false || stripos($block, 'error') !== false) return 'err-error';
    if (stripos($block, 'warning') !== false || stripos($block, 'deprecated') !== false) return 'err-warn';
    return 'err-info';
};
?>
<div class="tab-host">
    <div class="tabs" style="display:inline-flex">
        <button type="button" data-tab="actions" class="active"><?= View::e(Lang::t('logs.tab_actions')) ?></button>
        <button type="button" data-tab="errors"><?= View::e(Lang::t('logs.tab_errors')) ?>
            <?php if ($errorLog && !empty($errorLog['entries'])): ?><span class="tab-badge"><?= count($errorLog['entries']) ?></span><?php endif; ?>
        </button>
    </div>

    <!-- Action log -->
    <div data-tabpanel="actions">
        <div class="toolbar">
            <form method="get" data-live-search="logs">
                <input type="text" name="search" placeholder="<?= View::e(Lang::t('common.search')) ?>..." value="<?= View::e($filters['search']) ?>">
                <select name="action">
                    <option value=""><?= View::e(Lang::t('common.all')) ?></option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= View::e($a) ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>><?= View::e($label($a)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" type="submit"><?= View::e(Lang::t('common.filter')) ?></button>
            </form>
            <?php if (Auth::isRoot()): ?>
                <div class="toolbar-actions">
                    <form method="post" action="/logs/clear-actions" data-confirm="<?= View::e(Lang::t('logs.clear_actions_confirm')) ?>">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <button type="submit" class="btn btn-danger btn-sm" data-live-search-enabled="logs"<?= !$pg->items ? ' disabled' : '' ?>><?= View::e(Lang::t('logs.clear_actions')) ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
        <div class="live-search-results" id="search-results-logs" data-live-search-results="logs" tabindex="-1">
        <div class="card" style="padding:0">
            <?php if (!$pg->items): ?>
                <div class="empty"><?= View::e(Lang::t('logs.none')) ?></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="log-table">
                        <thead><tr>
                            <th style="width:32px"></th>
                            <th><?= View::e(Lang::t('logs.actor')) ?></th>
                            <th><?= View::e(Lang::t('logs.action')) ?></th>
                            <th><?= View::e(Lang::t('logs.target')) ?></th>
                            <th><?= View::e(Lang::t('logs.when')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($pg->items as $l): ?>
                            <?php
                            $a = (string) $l['action'];
                            $hasDetails = ($l['details'] ?? '') !== '' || ($l['ip'] ?? '') !== '' || ($l['actor_steamid'] ?? '') !== '';
                            $targetIsSteam = $l['target'] && Steam::isValidSteamId64((string) $l['target']);
                            ?>
                            <tr class="log-row<?= $hasDetails ? ' has-details' : '' ?>"<?= $hasDetails ? ' tabindex="0"' : '' ?>>
                                <td class="log-caret"><?= $hasDetails ? '<span class="caret">▸</span>' : '' ?></td>
                                <td><?= Steam::playerCell((string) ($l['actor_steamid'] ?? ''), (string) ($l['actor_name'] ?: Lang::t('log.system')), $avatars) ?></td>
                                <td><span class="badge <?= $badge($a) ?>" title="<?= View::e($a) ?>"><?= View::e($label($a)) ?></span></td>
                                <td>
                                    <?php if ($targetIsSteam): ?>
                                        <?= Steam::playerCell((string) $l['target'], (string) ($names[(string) $l['target']] ?? $l['target']), $avatars) ?>
                                    <?php else: ?><span class="mono"><?= View::e($l['target'] ?: '—') ?></span><?php endif; ?>
                                </td>
                                <td class="muted"><?= View::e($l['created_at']) ?></td>
                            </tr>
                            <?php if ($hasDetails): ?>
                            <tr class="log-detail" hidden>
                                <td></td>
                                <td colspan="4">
                                    <dl class="log-detail-grid">
                                        <?php if (($l['details'] ?? '') !== ''): ?>
                                            <dt><?= View::e(Lang::t('logs.details')) ?></dt><dd><?= View::e($l['details']) ?></dd>
                                        <?php endif; ?>
                                        <?php if (($l['actor_steamid'] ?? '') !== ''): ?>
                                            <dt><?= View::e(Lang::t('logs.actor')) ?></dt>
                                            <dd class="mono"><?= Steam::idLink((string) $l['actor_steamid']) ?></dd>
                                        <?php endif; ?>
                                        <?php if ($targetIsSteam): ?>
                                            <dt><?= View::e(Lang::t('logs.target')) ?></dt>
                                            <dd class="mono"><?= Steam::idLink((string) $l['target']) ?></dd>
                                        <?php endif; ?>
                                        <?php if (($l['ip'] ?? '') !== ''): ?>
                                            <dt><?= View::e(Lang::t('logs.ip')) ?></dt><dd class="mono"><?= View::e($l['ip']) ?></dd>
                                        <?php endif; ?>
                                    </dl>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php require BASE_PATH . '/app/Views/partials/pagination.php'; ?>
        </div>
    </div>

    <!-- Error log -->
    <div data-tabpanel="errors" style="display:none">
        <?php if ($errorLog !== null && Auth::isRoot() && !empty($errorLog['entries'])): ?>
            <div class="toolbar">
                <div class="spacer"></div>
                <div class="toolbar-actions">
                    <form method="post" action="/logs/clear-errors" data-confirm="<?= View::e(Lang::t('logs.clear_confirm')) ?>">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><?= View::e(Lang::t('logs.clear_errors')) ?></button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <?php if ($errorLog === null): ?>
                <div class="empty">
                    <?= View::e(Lang::t('logs.err_none')) ?><br>
                    <span class="muted"><?= View::e(Lang::t('logs.err_none_hint')) ?></span>
                </div>
            <?php elseif (empty($errorLog['entries'])): ?>
                <div class="empty"><?= View::e(Lang::t('logs.err_empty')) ?></div>
            <?php else: ?>
                <p class="hint" style="margin:0 0 14px"><?= View::e(Lang::t('logs.err_intro')) ?></p>
                <div class="err-list">
                    <?php foreach ($errorLog['entries'] as $block): ?>
                        <?php
                        $lines = preg_split('/\r?\n/', trim($block));
                        $first = $lines[0] ?? '';
                        $rest  = implode("\n", array_slice($lines, 1));
                        ?>
                        <div class="err-entry <?= $errClass($block) ?>">
                            <div class="err-head"><?= View::e($first) ?></div>
                            <?php if (trim($rest) !== ''): ?>
                                <pre class="err-trace"><?= View::e($rest) ?></pre>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    function toggle(row) {
        var detail = row.nextElementSibling;
        if (!detail || !detail.classList.contains('log-detail')) return;
        var open = !detail.hasAttribute('hidden');
        if (open) { detail.setAttribute('hidden', ''); } else { detail.removeAttribute('hidden'); }
        row.classList.toggle('open', !open);
    }
    document.addEventListener('click', function (event) {
        var row = event.target.closest('.log-row.has-details');
        if (row && !event.target.closest('a, button, input')) toggle(row);
    });
    document.addEventListener('keydown', function (event) {
        if (event.target.matches('.log-row.has-details') && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            toggle(event.target);
        }
    });
})();
</script>
