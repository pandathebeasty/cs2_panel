<?php
use App\Core\Lang;
use App\Core\View;
/** @var array $items @var int $totalCents @var int $netCents @var int $feeCents @var int $grossCents @var int $savedCents @var bool $codesOn @var string $code @var float $codePercent @var float $checkoutPercent @var int $expiresIn @var int $ttlMinutes @var string $currency @var bool $passFee @var ?array $me @var string $csrf */
$title = Lang::t('store.cart');
$L = fn(string $k) => View::e(Lang::t($k));
$cur = View::e($currency);
$fmt = fn(int $c) => number_format($c / 100, 2, '.', '');
?>
<div class="toolbar">
    <div class="muted" style="margin-right:auto"><?= $L('store.cart_intro') ?></div>
    <a class="btn btn-sm" href="<?= View::e(url('/store')) ?>"><?= $L('store.back') ?></a>
</div>

<?php if ($items): ?>
    <div class="cart-timer" id="cart-timer" data-left="<?= (int) $expiresIn ?>" role="status">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        <span><?= View::e(Lang::t('store.cart_expires_in', ['time' => ''])) ?> <strong id="cart-timer-value">--:--</strong></span>
        <span class="muted" style="margin-left:auto"><?= View::e(Lang::t('store.cart_ttl_hint', ['n' => (int) $ttlMinutes])) ?></span>
    </div>
<?php endif; ?>

<?php if (!$items): ?>
    <div class="card"><div class="empty"><?= $L('store.cart_empty') ?></div></div>
<?php else: ?>
    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th><?= $L('store.package') ?></th>
                    <th><?= $L('store.for') ?></th>
                    <th><?= $L('store.amount') ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <?php
                        $p = $it['pkg'];
                        $isCredits = ($p['type'] ?? '') === 'credits';
                        $typeLabel = $isCredits
                            ? $L('store.type_credits')
                            : (['vip' => $L('store.type_vip'), 'admin' => $L('store.type_admin'), 'vip_admin' => $L('store.type_vip_admin')][$p['type']] ?? $L('store.type_vip'));
                        $typeClass = $isCredits ? 'credits' : ($p['type'] === 'vip_admin' ? 'both' : $p['type']);
                        $vdur = (int) ($p['days'] ?? 0) > 0 ? View::e(Lang::t('discord.days_n', [(int) $p['days']])) : $L('common.permanent');
                        $adur = (int) ($p['admin_days'] ?? 0) > 0 ? View::e(Lang::t('discord.days_n', [(int) $p['admin_days']])) : $L('common.permanent');
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:600"><?= View::e($p['name']) ?> <span class="store-type store-type-<?= $typeClass ?>" style="margin-left:4px"><?= $typeLabel ?></span></div>
                            <div class="muted" style="font-size:12px">
                                <?php if ($isCredits): ?>
                                    <?= number_format((int) ($p['credits'] ?? 0)) ?> <?= $L('credits.credits') ?>
                                <?php else: ?>
                                    <?php if ((string) ($p['vip_group'] ?? '') !== ''): ?><?= View::e($p['vip_group']) ?> · <?= $vdur ?><?php endif; ?>
                                    <?php if ((int) ($p['admin_group_id'] ?? 0) > 0): ?> <?= (string) ($p['vip_group'] ?? '') !== '' ? '·' : '' ?> <?= View::e(\App\Models\GroupModel::displayName((string) ($p['admin_group_name'] ?? ''))) ?> (<?= $L('store.admin') ?>) · <?= $adur ?><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($it['conflict'])): ?>
                                <div class="cart-warn" role="alert">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    <span><?= View::e($it['conflict']) ?></span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($it['is_gift']): ?>
                                <span class="badge badge-mute"><?= $L('store.gift') ?></span>
                                <span class="mono" style="font-size:12px"><?= View::e($it['ben_name'] !== '' ? $it['ben_name'] : $it['beneficiary']) ?></span>
                            <?php else: ?>
                                <span class="muted"><?= $L('store.for_me') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($it['discount_on']): ?><span class="store-old-price"><?= View::e($it['list_price']) ?></span><?php endif; ?>
                            <?= View::e($it['total']) ?> <?= $cur ?>
                        </td>
                        <td style="white-space:nowrap">
                            <form method="post" action="<?= View::e(url('/store/cart/remove')) ?>" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                <input type="hidden" name="line" value="<?= View::e($it['line']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><?= $L('common.delete') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top:16px;max-width:480px;margin-left:auto">
        <?php if ($codesOn): ?>
            <form method="post" action="<?= View::e(url('/store/cart/code')) ?>" class="cart-code">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <?php if ($code !== ''): ?>
                    <div class="cart-line">
                        <span><span class="badge badge-active"><?= View::e($code) ?></span> <span class="muted">−<?= View::e(rtrim(rtrim(number_format($codePercent, 2, '.', ''), '0'), '.')) ?>%</span></span>
                        <button type="submit" name="remove" value="1" class="btn btn-ghost btn-sm"><?= $L('common.delete') ?></button>
                    </div>
                <?php else: ?>
                    <div class="field" style="margin:0">
                        <label><?= $L('store.code_label') ?></label>
                        <div style="display:flex;gap:8px">
                            <input type="text" name="code" maxlength="48" placeholder="SUMMER20" style="flex:1">
                            <button type="submit" class="btn"><?= $L('store.code_apply') ?></button>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($checkoutPercent > 0): ?>
            <div class="cart-line"><span class="muted"><?= $L('store.checkout_discount') ?></span><span>−<?= View::e(rtrim(rtrim(number_format($checkoutPercent, 2, '.', ''), '0'), '.')) ?>%</span></div>
        <?php endif; ?>
        <?php if ($savedCents > 0): ?>
            <div class="cart-line"><span class="muted"><?= $L('store.price_before') ?></span><span class="store-old-price" style="font-size:1em"><?= $fmt($grossCents) ?> <?= $cur ?></span></div>
            <div class="cart-line"><span class="muted"><?= $L('store.you_save') ?></span><span><?= $fmt($savedCents) ?> <?= $cur ?></span></div>
        <?php endif; ?>
        <?php if ($passFee): ?>
            <div class="cart-line"><span class="muted"><?= $L('store.price_net') ?></span><span><?= $fmt($netCents) ?> <?= $cur ?></span></div>
            <div class="cart-line"><span class="muted"><?= $L('store.price_fee') ?></span><span><?= $fmt($feeCents) ?> <?= $cur ?></span></div>
        <?php endif; ?>
        <div class="cart-line cart-total"><span><?= $L('store.total') ?></span><span><?= $fmt($totalCents) ?> <?= $cur ?></span></div>

        <form method="post" action="<?= View::e(url('/store/cart/checkout')) ?>" style="margin-top:12px">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <div class="field">
                <label><?= $L('store.email') ?> *</label>
                <input type="email" name="email" required placeholder="you@example.com"<?php if (!empty($me['email'])): ?> value="<?= View::e((string) $me['email']) ?>"<?php endif; ?>>
                <p class="hint" style="margin-top:4px"><?= $L('store.email_hint') ?></p>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px">
                <button type="submit" class="btn btn-primary" style="flex:1"><?= $L('store.checkout') ?></button>
            </div>
        </form>
        <form method="post" action="<?= View::e(url('/store/cart/clear')) ?>" style="margin-top:8px">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <button type="submit" class="btn btn-ghost btn-sm"><?= $L('store.cart_clear') ?></button>
        </form>
    </div>

    <script>
    (function () {
        var box = document.getElementById('cart-timer');
        if (!box) return;
        var out = document.getElementById('cart-timer-value');
        var left = parseInt(box.dataset.left, 10) || 0;
        function tick() {
            if (left <= 0) {
                // The server already dropped the line; reload to show the state.
                window.location.reload();
                return;
            }
            var m = Math.floor(left / 60), s = left % 60;
            out.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            box.classList.toggle('is-urgent', left <= 60);
            left--;
        }
        tick();
        window.setInterval(tick, 1000);
    })();
    </script>
<?php endif; ?>
