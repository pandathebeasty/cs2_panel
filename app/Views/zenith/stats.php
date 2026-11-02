<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Steam;
use App\Core\View;
/** @var \App\Core\Paginator|null $board @var string $csrf */
$title = Lang::t('stats.title');
$L = fn(string $k) => View::e(Lang::t($k));
$canManage = Auth::canManage();
$offset = $board ? ($board->page - 1) * $board->perPage : 0;
?>
<?php if ($board === null): ?>
    <div class="card"><div class="empty"><?= $L('stats.no_source') ?></div></div>
<?php else: ?>
    <?php $pg = $board; ?>
    <?php $avatars = $pg->items ? Steam::avatars(array_column($pg->items, 'steam_id')) : []; ?>
    <div class="card" style="padding:0">
        <?php if (!$pg->items): ?>
            <div class="empty"><?= $L('stats.none') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th><?= $L('common.player') ?></th><th><?= $L('common.steamid') ?></th><th><?= $L('dash.kills') ?></th><th><?= $L('dash.deaths') ?></th><th><?= $L('ranks.assists') ?></th><th><?= $L('ranks.hs') ?></th><th><?= $L('stats.kd') ?></th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($pg->items as $i => $r): ?>
                        <?php
                        $kills = (int) $r['kills'];
                        $deaths = (int) $r['deaths'];
                        $assists = (int) ($r['assists'] ?? 0);
                        $hs = (int) ($r['headshots'] ?? 0);
                        $kd = $deaths > 0 ? round($kills / $deaths, 2) : $kills;
                        ?>
                        <tr>
                            <td class="muted"><?= $offset + $i + 1 ?></td>
                            <td>
                                <?= Steam::playerCell((string) $r['steam_id'], (string) ($r['name'] ?: Lang::t('common.player')), $avatars) ?>
                            </td>
                            <td class="mono"><?= Steam::idLink((string) $r['steam_id']) ?></td>
                            <td style="font-weight:700;color:var(--green)"><?= number_format($kills) ?></td>
                            <td class="muted"><?= number_format($deaths) ?></td>
                            <td class="muted"><?= number_format($assists) ?></td>
                            <td class="muted"><?= number_format($hs) ?></td>
                            <td style="font-weight:600"><?= $kd ?></td>
                            <?php if ($canManage): ?>
                            <td>
                                <form method="post" action="/zenith/stats/reset" data-confirm="<?= $L('stats.reset_one') ?>" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                    <input type="hidden" name="steam_id" value="<?= View::e($r['steam_id']) ?>">
                                    <button class="btn btn-sm btn-danger" type="submit"><?= $L('common.reset') ?></button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php require BASE_PATH . '/app/Views/partials/pagination.php'; ?>
<?php endif; ?>
