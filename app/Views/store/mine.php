<?php
use App\Core\Lang;
use App\Core\Steam;
use App\Core\Store;
use App\Core\View;
/** @var \App\Core\Paginator $orders @var array $avatars @var string $csrf */
$title = Lang::t('store.my_orders');
$L = fn(string $k) => View::e(Lang::t($k));
$pg = $orders;
$avatars = $avatars ?? [];
?>
<div class="toolbar">
    <div class="muted" style="margin-right:auto"><?= $L('store.my_orders_intro') ?></div>
    <a class="btn btn-sm" href="<?= View::e(url('/store')) ?>"><?= $L('store.back') ?></a>
</div>

<div class="card" style="padding:0">
    <?php if (!$pg->items): ?>
        <div class="empty"><?= $L('store.no_orders') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th><?= $L('store.order') ?></th>
                    <th><?= $L('store.package') ?></th>
                    <th><?= $L('store.vip') ?></th>
                    <th><?= $L('store.amount') ?></th>
                    <th><?= $L('store.for') ?></th>
                    <th><?= $L('common.status') ?></th>
                    <th><?= $L('common.created') ?></th>
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
                        <td><?= View::e($o['package_name']) ?></td>
                        <td><?php if ($oitems): ?><span class="muted">—</span><?php else: ?><?= View::e($o['vip_group']) ?> · <?= $duration ?><?php endif; ?></td>
                        <td><?= View::e(Store::formatCents((int) $o['total_cents'])) ?> <?= View::e($o['currency']) ?></td>
                        <td><?php if ($oitems): ?><span class="muted">—</span><?php elseif ($isGift): ?><span class="badge badge-mute"><?= $L('store.gift') ?></span> <?= Steam::playerCell((string) $o['beneficiary_steamid'], (string) ($o['beneficiary_name'] ?? ''), $avatars) ?><?php else: ?><span class="muted"><?= $L('store.for_me') ?></span><?php endif; ?></td>
                        <td><span class="badge badge-<?= $statusClass ?>"><?= View::e(strtoupper((string) $o['status'])) ?></span></td>
                        <td class="muted"><?= View::e((string) $o['created_at']) ?></td>
                    </tr>
                    <?php if ($oitems): ?>
                        <tr class="order-items-row"><td colspan="7">
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
                                    <?php if ($iGift): ?><span class="oi-for muted">→ <?= $L('store.gift') ?>: <?= Steam::playerCell((string) $oi['beneficiary_steamid'], (string) ($oi['beneficiary_name'] ?? ''), $avatars) ?></span><?php endif; ?>
                                    <span class="oi-amt muted"><?= View::e(Store::formatCents((int) $oi['total_cents'])) ?> <?= View::e($o['currency']) ?></span>
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
