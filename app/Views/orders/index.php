<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\Store;
use App\Core\View;
/** @var \App\Core\Paginator $orders @var array $filters @var array $avatars @var int $revenueNet @var string $currency @var bool $isRoot @var string $csrf */
$title = Lang::t('orders.title');
$L = fn(string $k) => View::e(Lang::t($k));
$pg = $orders;
$liveSearchKey = 'orders';
$avatars = $avatars ?? [];
$cur = View::e($currency);

$playerCell = function (string $steamId, string $name) use ($avatars): string {
    $valid = Steam::isValidSteamId64($steamId);
    $label = View::e($name !== '' ? $name : ($steamId !== '' ? $steamId : '—'));
    if (!$valid) {
        return '<span class="pname">' . $label . '</span>';
    }
    $img = View::e($avatars[$steamId] ?? Steam::avatarPlaceholder());
    return '<a class="player-cell" href="' . View::e(Steam::profileUrl($steamId)) . '" target="_blank" rel="noopener">'
        . '<img class="player-avatar" src="' . $img . '" alt="" loading="lazy">'
        . '<span class="pname">' . $label . '</span></a>';
};
?>
<div class="toolbar">
    <form method="get" data-live-search="orders">
        <input type="text" name="search" placeholder="<?= $L('orders.search_ph') ?>" value="<?= View::e($filters['search']) ?>">
        <select name="status">
            <option value=""><?= $L('common.all_statuses') ?></option>
            <?php foreach (['pending', 'paid', 'refunded', 'cancelled'] as $st): ?>
                <option value="<?= $st ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= View::e(strtoupper($st)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit"><?= $L('common.filter') ?></button>
    </form>
    <div class="toolbar-actions">
        <span class="cc-chip cc-chip-prize"><?= $L('orders.revenue_net') ?>: <?= View::e(Store::formatCents($revenueNet)) ?> <?= $cur ?></span>
    </div>
</div>

<?php require BASE_PATH . '/app/Views/partials/live-search-status.php'; ?>
<div class="live-search-results" id="search-results-orders" data-live-search-results="orders" tabindex="-1">
<div class="card" style="padding:0">
    <?php if (!$pg->items): ?>
        <div class="empty"><?= $L('orders.none') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th><?= $L('store.order') ?></th>
                    <th><?= $L('orders.buyer') ?></th>
                    <th><?= $L('orders.beneficiary') ?></th>
                    <th><?= $L('store.package') ?></th>
                    <th><?= $L('store.vip') ?></th>
                    <th><?= $L('store.amount') ?></th>
                    <th><?= $L('common.status') ?></th>
                    <th><?= $L('common.created') ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($pg->items as $o): ?>
                    <?php
                        $days = (int) $o['vip_days'];
                        $duration = $days > 0 ? View::e(Lang::t('discord.days_n', [$days])) : $L('common.permanent');
                        $isGift = (string) $o['beneficiary_steamid'] !== (string) $o['buyer_steamid'];
                        $statusClass = ['paid' => 'active', 'refunded' => 'expired', 'cancelled' => 'mute', 'pending' => 'mute'][$o['status']] ?? 'mute';
                        $oitems = ($itemsByOrder ?? [])[(int) $o['id']] ?? [];
                    ?>
                    <tr>
                        <td class="mono"><?= View::e($o['code']) ?></td>
                        <td><?= $playerCell((string) $o['buyer_steamid'], (string) ($o['buyer_name'] ?? '')) ?></td>
                        <?php if ($oitems): ?>
                            <td colspan="2"><span class="muted"><?= View::e($o['package_name']) ?></span></td>
                        <?php else: ?>
                        <td>
                            <?php if ($isGift): ?>
                                <?= $playerCell((string) $o['beneficiary_steamid'], (string) ($o['beneficiary_name'] ?? '')) ?>
                                <span class="badge badge-mute" style="margin-left:4px"><?= $L('store.gift') ?></span>
                            <?php else: ?>
                                <span class="muted"><?= $L('store.for_me') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= View::e($o['package_name']) ?></td>
                        <?php endif; ?>
                        <td><?php if ($oitems): ?><span class="muted">—</span><?php elseif ((string) ($o['vip_group'] ?? '') !== ''): ?><?= View::e($o['vip_group']) ?> · <?= $duration ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <td><?= View::e(Store::formatCents((int) $o['total_cents'])) ?> <?= $cur ?></td>
                        <td><span class="badge badge-<?= $statusClass ?>"><?= View::e(strtoupper((string) $o['status'])) ?></span></td>
                        <td class="muted"><?= View::e((string) $o['created_at']) ?></td>
                        <td style="white-space:nowrap">
                            <?php $tf = $testFeat ?? []; ?>
                            <?php if ($isRoot && $o['status'] === 'paid'): ?>
                                <form method="post" action="<?= View::e(url('/orders/' . (int) $o['id'] . '/refund')) ?>" style="display:inline" data-confirm="<?= $L('orders.refund_confirm') ?>">
                                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                    <button class="btn btn-sm btn-danger" type="submit"><?= $L('orders.refund') ?></button>
                                </form>
                                <?php if (empty($o['vip_granted'])): ?>
                                    <form method="post" action="<?= View::e(url('/orders/' . (int) $o['id'] . '/regrant')) ?>" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <button class="btn btn-sm" type="submit"><?= $L('orders.regrant') ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($tf['resend_email']) && trim((string) ($o['buyer_email'] ?? '')) !== ''): ?>
                                    <form method="post" action="<?= View::e(url('/orders/' . (int) $o['id'] . '/test-email')) ?>" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <button class="btn btn-sm" type="submit" title="<?= $L('orders.test_email_hint') ?>"><?= $L('orders.test_email') ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($tf['discord_purchase'])): ?>
                                    <form method="post" action="<?= View::e(url('/orders/' . (int) $o['id'] . '/test-discord')) ?>" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <button class="btn btn-sm" type="submit" title="<?= $L('orders.test_discord_hint') ?>"><?= $L('orders.test_discord') ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($isRoot && !empty($tf['mark_paid']) && $o['status'] === 'pending'): ?>
                                <form method="post" action="<?= View::e(url('/orders/' . (int) $o['id'] . '/test-paid')) ?>" style="display:inline" data-confirm="<?= $L('orders.test_paid_confirm') ?>">
                                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                    <button class="btn btn-sm" type="submit" title="<?= $L('orders.test_paid_hint') ?>"><?= $L('orders.test_paid') ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($oitems): ?>
                        <tr class="order-items-row"><td colspan="9">
                            <div class="order-items">
                            <?php foreach ($oitems as $oi): ?>
                                <?php
                                    $ivd = (int) $oi['vip_days']; $ivDur = $ivd > 0 ? View::e(Lang::t('discord.days_n', [$ivd])) : $L('common.permanent');
                                    $iad = (int) ($oi['admin_days'] ?? 0); $iaDur = $iad > 0 ? View::e(Lang::t('discord.days_n', [$iad])) : $L('common.permanent');
                                    $iGift = (string) $oi['beneficiary_steamid'] !== (string) $o['buyer_steamid'];
                                ?>
                                <div class="order-item-line">
                                    <span class="oi-name"><?= View::e($oi['package_name']) ?></span>
                                    <?php if ((string) ($oi['vip_group'] ?? '') !== ''): ?><span class="badge badge-mute"><?= View::e($oi['vip_group']) ?> · <?= $ivDur ?></span><?php endif; ?>
                                    <?php if ((int) ($oi['admin_group_id'] ?? 0) > 0): ?><span class="badge badge-role-admin"><?= View::e(\App\Models\GroupModel::displayName((string) ($oi['admin_group_name'] ?? ''))) ?> · <?= $iaDur ?></span><?php endif; ?>
                                    <span class="oi-for muted">→ <?php if ($iGift): ?><?= $L('store.gift') ?>: <?= $playerCell((string) $oi['beneficiary_steamid'], (string) ($oi['beneficiary_name'] ?? '')) ?><?php else: ?><?= $L('store.for_me') ?><?php endif; ?></span>
                                    <span class="oi-amt muted"><?= View::e(Store::formatCents((int) $oi['total_cents'])) ?> <?= $cur ?></span>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require BASE_PATH . '/app/Views/partials/pagination.php'; ?>
</div>
