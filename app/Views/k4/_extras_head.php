<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/**
 * Shared header of the K4 missions / achievements / seasons pages.
 * @var string $page @var ?string $steamId @var ?array $player @var array $matches @var string $q @var bool $isSelf
 */
$L = fn(string $k) => View::e(Lang::t($k));
$k4Active = $page;
$headAvatar = $steamId ? (Steam::avatars([$steamId])[$steamId] ?? Steam::avatarPlaceholder()) : null;
$extraQuery = $page === 'seasons' && isset($season) ? '&season=' . rawurlencode((string) $season) : '';
?>
<div class="subtabs">
    <a class="subtab" href="/k4/ranks"><?= $L('ranks.subtab_ranks') ?></a>
    <a class="subtab" href="/k4/playtime"><?= $L('ranks.subtab_playtime') ?></a>
    <?php require __DIR__ . '/_extras_tabs.php'; ?>
</div>

<div class="k4x-head">
    <div class="k4x-who">
        <?php if ($player): ?>
            <img class="k4x-avatar" src="<?= View::e($headAvatar) ?>" alt="" loading="lazy">
            <div class="k4x-who-text">
                <span class="k4x-eyebrow"><?= $isSelf ? $L('k4x.your_profile') : $L('k4x.viewing') ?></span>
                <strong><?= Steam::nameLink((string) $steamId, (string) ($player['name'] ?: Lang::t('common.player'))) ?></strong>
                <span class="k4x-sub"><?= number_format((int) ($player['points'] ?? 0)) ?> <?= $L('k4x.points') ?><?php if (!empty($player['rank_name'])): ?> · <?= View::e((string) $player['rank_name']) ?><?php endif; ?></span>
            </div>
        <?php else: ?>
            <span class="k4x-icon k4x-icon-lg" style="--i:url('<?= View::e(\App\Models\K4ExtrasModel::uiIcon('profile')) ?>')"></span>
            <div class="k4x-who-text">
                <span class="k4x-eyebrow"><?= $L('k4x.player') ?></span>
                <strong><?= $L('k4x.pick_player') ?></strong>
            </div>
        <?php endif; ?>
    </div>
    <form class="k4x-search" method="get" action="/k4/<?= View::e($page) ?>" role="search"
          data-k4x-suggest="<?= View::e(\App\Core\Url::to('/k4/players/suggest')) ?>"
          data-k4x-go="<?= View::e(\App\Core\Url::to('/k4/' . $page) . '?player={id}' . $extraQuery) ?>"
          data-k4x-empty="<?= $L('k4x.no_match') ?>">
        <?php if ($page === 'seasons' && isset($season)): ?><input type="hidden" name="season" value="<?= View::e((string) $season) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= View::e($q) ?>" placeholder="<?= $L('k4x.search_ph') ?>" autocomplete="off" maxlength="64" aria-autocomplete="list" aria-controls="k4x-suggest" aria-expanded="false">
        <div class="k4x-suggest" id="k4x-suggest" role="listbox" hidden></div>
        <button class="btn btn-primary btn-sm" type="submit"><?= $L('common.search') ?></button>
        <?php if (!$isSelf && \App\Core\Auth::steamId()): ?>
            <a class="btn btn-sm" href="/k4/<?= View::e($page) ?>?player=<?= View::e((string) \App\Core\Auth::steamId()) . $extraQuery ?>"><?= $L('k4x.me') ?></a>
        <?php endif; ?>
    </form>
</div>

<?php if ($q !== '' && count($matches) !== 1): ?>
    <div class="k4x-matches">
        <?php if (!$matches): ?>
            <span class="muted"><?= $L('k4x.no_match') ?></span>
        <?php else: ?>
            <?php $mAv = Steam::avatars(array_column($matches, 'steam_id')); ?>
            <?php foreach ($matches as $m): ?>
                <a class="k4x-chip" href="/k4/<?= View::e($page) ?>?player=<?= View::e((string) $m['steam_id']) . $extraQuery ?>">
                    <img src="<?= View::e($mAv[(string) $m['steam_id']] ?? Steam::avatarPlaceholder()) ?>" alt="" loading="lazy">
                    <?= View::e((string) ($m['name'] ?: $m['steam_id'])) ?>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
