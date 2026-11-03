<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var array $rows @var bool $available @var bool $error @var array $avatars */
$title = Lang::t('evasion.title');
$L = fn(string $k) => View::e(Lang::t($k));
?>
<div class="x-page-head">
    <div>
        <span class="x-eyebrow"><?= $L('nav.bans') ?></span>
        <h1 class="x-page-title"><?= $L('evasion.title') ?></h1>
        <p class="muted"><?= $L('evasion.sub') ?></p>
    </div>
    <span class="x-count x-count-lg"><?= count($rows) ?></span>
</div>

<?php if (!$available): ?>
    <div class="x-callout x-callout-warn"><?= $L('player.alts_unavailable') ?></div>
<?php elseif ($error): ?>
    <div class="x-callout x-callout-warn"><?= $L('evasion.error') ?></div>
<?php elseif (!$rows): ?>
    <div class="card"><div class="x-empty x-empty-lg"><span class="x-empty-ico" aria-hidden="true">✓</span><?= $L('evasion.none') ?></div></div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap"><table class="x-stack">
            <thead><tr><th><?= $L('evasion.suspect') ?></th><th><?= $L('common.ip') ?></th><th><?= $L('evasion.seen') ?></th><th><?= $L('evasion.banned_account') ?></th><th><?= $L('common.reason') ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="<?= $L('evasion.suspect') ?>">
                        <a class="player-cell" <?= Steam::linkAttrs($r['steamid']) ?>>
                            <img class="player-avatar" src="<?= View::e($avatars[$r['steamid']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                            <span class="pname"><?= View::e((string) ($r['name'] ?: $r['steamid'])) ?></span>
                        </a>
                    </td>
                    <td data-label="<?= $L('common.ip') ?>" class="mono"><?= View::e(\App\Models\AltModel::ip($r['address'])) ?></td>
                    <td data-label="<?= $L('evasion.seen') ?>" class="muted"><?= View::e((string) $r['used_at']) ?></td>
                    <td data-label="<?= $L('evasion.banned_account') ?>">
                        <?php if ($r['banned_steamid']): ?>
                            <a <?= Steam::linkAttrs($r['banned_steamid']) ?>><?= View::e((string) ($r['banned_name'] ?: $r['banned_steamid'])) ?></a>
                        <?php else: ?>
                            <?= View::e((string) ($r['banned_name'] ?: '—')) ?>
                        <?php endif; ?>
                        <span class="muted x-small">#<?= (int) $r['ban_id'] ?></span>
                    </td>
                    <td data-label="<?= $L('common.reason') ?>"><?= View::e((string) ($r['reason'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
<?php endif; ?>
