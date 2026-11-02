<?php
/**
 * Mini-cart dropdown contents. Rendered inline on the store page and re-rendered
 * via AJAX (StoreController::miniCart) after add-to-cart.
 * @var array $cartItems @var int $cartTotal @var string $currency @var string $csrf
 */
use App\Core\Lang;
use App\Core\View;
$L = fn(string $k) => View::e(Lang::t($k));
$items = $cartItems ?? [];
$cur = View::e($currency ?? 'EUR');
$fmt = fn(int $c) => number_format($c / 100, 2, '.', '');
?>
<?php if (!$items): ?>
    <div class="mini-cart-empty muted"><?= $L('store.cart_empty') ?></div>
<?php else: ?>
    <div class="mini-cart-items">
        <?php foreach ($items as $it): ?>
            <?php $p = $it['pkg']; ?>
            <div class="mini-cart-item">
                <span class="mci-name"><?= View::e($p['name']) ?><?php if ($it['is_gift']): ?> <span class="badge badge-mute"><?= $L('store.gift') ?></span><?php endif; ?></span>
                <span class="mci-right">
                    <span class="mci-amt"><?= View::e($it['total']) ?> <?= $cur ?></span>
                    <button type="button" class="mci-remove js-mini-remove" data-line="<?= View::e($it['line']) ?>" title="<?= $L('store.remove') ?>" aria-label="<?= $L('store.remove') ?>">🗑️</button>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="mini-cart-total">
        <span><?= $L('store.total') ?></span>
        <span><?= $fmt((int) ($cartTotal ?? 0)) ?> <?= $cur ?></span>
    </div>
    <div class="mini-cart-actions">
        <a href="<?= View::e(url('/store/cart')) ?>" class="btn btn-primary btn-sm" style="flex:1;text-align:center"><?= $L('store.view_cart') ?></a>
    </div>
<?php endif; ?>
