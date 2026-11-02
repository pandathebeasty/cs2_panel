<?php
use App\Core\Lang;
use App\Core\View;
/** @var array $packages @var string $currency @var bool $passFee @var bool $loggedIn @var ?array $me @var int $cartCount @var string $csrf */
$title = Lang::t('nav.store');
$L = fn(string $k) => View::e(Lang::t($k));
$cur = View::e($currency);
$result = $result ?? null;
$cartCount = $cartCount ?? 0;
?>

<?php if ($result): ?>
    <?php
        $rs = $result['status'];
        $ro = $result['order'];
        $bannerType = $rs === 'paid' ? 'success' : ($rs === 'cancelled' ? 'info' : 'error');
        $bannerMsg = $rs === 'paid' ? Lang::t('store.success_msg')
            : ($rs === 'cancelled' ? Lang::t('store.cancelled_msg') : Lang::t('store.failed_msg'));
    ?>
    <div class="flash flash-<?= View::e($bannerType) ?>" style="margin-bottom:16px">
        <strong><?= View::e($rs === 'paid' ? Lang::t('store.success_title') : ($rs === 'cancelled' ? Lang::t('store.cancelled_title') : Lang::t('store.failed_title'))) ?></strong>
        — <?= View::e($bannerMsg) ?>
        <span class="mono" style="margin-left:6px"><?= $L('store.order') ?>: <?= View::e($ro['code']) ?></span>
    </div>
<?php endif; ?>

<div class="toolbar">
    <div class="muted" style="margin-right:auto"><?= $L('store.intro') ?></div>
    <div class="mini-cart" id="mini-cart">
        <button type="button" class="btn btn-sm" id="cart-link">🛒 <?= $L('store.cart') ?> <span class="cart-count"<?= $cartCount > 0 ? '' : ' style="display:none"' ?>><?= (int) $cartCount ?></span></button>
        <div class="mini-cart-panel" id="mini-cart-panel" hidden>
            <div class="mini-cart-body" id="mini-cart-body">
                <?php require BASE_PATH . '/app/Views/store/_minicart.php'; ?>
            </div>
        </div>
    </div>
    <a class="btn btn-sm" href="<?= View::e(url('/store/mine')) ?>"><?= $L('store.my_orders') ?></a>
</div>

<?php if (!$loggedIn): ?>
    <div class="card" style="border-left:3px solid var(--accent,#e01e37);margin-bottom:18px">
        <div style="padding:12px 16px" class="muted">
            <?= $L('store.login_required') ?>
            <a class="btn-steam-sm" href="<?= View::e(url('/auth/steam')) ?>" style="margin-left:10px">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.5 2 2 6.5 2 12l5.4 2.2a2.8 2.8 0 011.6-.5l2.4-3.5v-.1a3.7 3.7 0 113.7 3.7h-.1l-3.4 2.5a2.8 2.8 0 01-5.5.7L2 15.4A10 10 0 1012 2z"/></svg>
                <?= $L('auth.login_steam') ?>
            </a>
        </div>
    </div>
<?php endif; ?>

<?php
    // Whether the "See benefits" button may show (page enabled site-wide).
    $benefitsOn = \App\Core\Settings::bool('benefits_enabled', false);

                                                                            
    $renderCard = function (array $p) use ($L, $cur, $passFee, $loggedIn, $csrf, $benefitsOn) {
        $days = (int) $p['days'];
        $duration = $days > 0 ? View::e(Lang::t('discord.days_n', [$days])) : $L('common.permanent');
        $typeLabel = ['vip' => $L('store.type_vip'), 'admin' => $L('store.type_admin'), 'vip_admin' => $L('store.type_vip_admin')][$p['type']] ?? $L('store.type_vip');
        $typeClass = ['vip' => 'store-type-vip', 'admin' => 'store-type-admin', 'vip_admin' => 'store-type-both'][$p['type']] ?? 'store-type-vip';
        ?>
        <div class="store-card card<?= !empty($p['discount_on']) ? ' store-card-discounted' : '' ?>">
            <?php if (!empty($p['discount_on'])): ?>
                <span class="store-discount-badge">-<?= View::e(rtrim(rtrim(number_format((float) $p['discount_percent'], 1, '.', ''), '0'), '.')) ?>%</span>
            <?php endif; ?>
            <div class="store-card-head">
                <div class="store-name"><?= View::e($p['name']) ?></div>
                <span class="store-type <?= $typeClass ?>"><?= $typeLabel ?></span>
            </div>
            <div class="store-perks">
                <?php if ((string) $p['vip_group'] !== ''): ?>
                    <span class="badge badge-mute"><?= View::e($p['vip_group']) ?> · <?= $duration ?></span>
                <?php endif; ?>
                <?php if ((int) ($p['admin_group_id'] ?? 0) > 0): ?>
                    <?php $adays = (int) ($p['admin_days'] ?? 0); $adur = $adays > 0 ? View::e(Lang::t('discord.days_n', [$adays])) : $L('common.permanent'); ?>
                    <span class="badge badge-role-admin"><?= View::e(\App\Models\GroupModel::displayName((string) ($p['admin_group_name'] ?? ''))) ?> · <?= $adur ?></span>
                <?php endif; ?>
                <?php if ((int) ($p['bonus_credits'] ?? 0) > 0): ?>
                    <span class="badge badge-active">+<?= number_format((int) $p['bonus_credits']) ?> <?= $L('credits.credits') ?></span>
                <?php endif; ?>
            </div>
            <?php
                // Auto description: "{name} package for {days}." or, when the
                // package is permanent, "Permanent {name} package.". The days
                // come from the VIP duration, or admin duration for admin-only.
                $isAdminOnly = ($p['type'] ?? '') === 'admin';
                $descDays = $isAdminOnly ? (int) ($p['admin_days'] ?? 0) : $days;
                $autoDesc = $descDays > 0
                    ? Lang::t('store.auto_desc', ['name' => (string) $p['name'], 'days' => Lang::t('discord.days_n', [$descDays])])
                    : Lang::t('store.auto_desc_perm', ['name' => (string) $p['name']]);
            ?>
            <p class="muted store-desc store-auto-desc"><?= View::e($autoDesc) ?></p>
            <?php if ($p['description'] !== ''): ?>
                <p class="muted store-desc"><?= View::e($p['description']) ?></p>
            <?php endif; ?>

            <div class="store-price">
                <div class="store-total">
                    <?php if (!empty($p['discount_on'])): ?>
                        <span class="store-old-price"><?= View::e($p['list_price']) ?></span>
                    <?php endif; ?>
                    <span class="store-current-price"><?= View::e($p['net']) ?> <?= $cur ?></span>
                </div>
                <?php if ($passFee): ?>
                    <div class="store-breakdown muted">
                        <?= $L('store.price_with_fee') ?>: <?= View::e($p['total']) ?> <?= $cur ?>
                        <span class="store-fee-part">(+<?= View::e($p['fee']) ?> <?= $cur ?> <?= $L('store.price_fee') ?>)</span>
                    </div>
                <?php endif; ?>
                <?php
                    // Validity window of an active discount, always 24-hour clock.
                    $dFrom = trim((string) ($p['discount_from'] ?? ''));
                    $dTo   = trim((string) ($p['discount_to'] ?? ''));
                    $fmt   = static fn(string $v): string => date('d.m.Y H:i', (int) strtotime($v));
                    $period = '';
                    if (!empty($p['discount_on'])) {
                        if ($dFrom !== '' && $dTo !== '') {
                            $period = Lang::t('store.discount_between', ['from' => $fmt($dFrom), 'to' => $fmt($dTo)]);
                        } elseif ($dTo !== '') {
                            $period = Lang::t('store.discount_until', ['date' => $fmt($dTo)]);
                        } elseif ($dFrom !== '') {
                            $period = Lang::t('store.discount_from', ['date' => $fmt($dFrom)]);
                        }
                    }
                ?>
                <?php if ($period !== ''): ?>
                    <div class="store-discount-period muted"><?= View::e($period) ?></div>
                <?php endif; ?>
            </div>

            <form method="post" action="<?= View::e(url('/store/cart/add')) ?>" class="store-buy js-add-cart-form">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="package_id" value="<?= View::e($p['id']) ?>">

                <label class="check store-gift-toggle">
                    <input type="checkbox" name="is_gift" value="1" class="js-gift-toggle">
                    <span><?= $L('store.gift') ?></span>
                </label>
                <div class="field js-gift-field" style="display:none;margin-top:6px">
                    <label><?= $L('store.gift_steamid') ?></label>
                    <input type="text" name="gift_steamid" placeholder="STEAM_0:1:... / 7656119...">
                    <p class="hint" style="margin-top:4px"><?= $L('store.gift_hint') ?></p>
                </div>

                <?php if ($loggedIn): ?>
                    <button type="submit" class="btn btn-primary store-buy-btn js-add-cart-btn"><?= $L('store.add_to_cart') ?></button>
                <?php else: ?>
                    <a href="<?= View::e(url('/auth/steam')) ?>" class="btn btn-primary store-buy-btn"><?= $L('store.add_to_cart') ?></a>
                <?php endif; ?>
            </form>
            <?php if ($benefitsOn && !empty($p['benefits_button'])): ?>
                <a href="<?= View::e(url('/benefits')) ?>" class="btn btn-ghost store-benefits-btn"><?= $L('store.see_benefits') ?></a>
            <?php endif; ?>
        </div>
        <?php
    };

    // Render empty groups too, so the store sections keep a stable layout.
                                                                            
    $sectionDefs = [
        'vip'       => $L('store.type_vip'),
        'admin'     => $L('store.type_admin'),
        'vip_admin' => $L('store.type_vip_admin'),
    ];
?>

<?php foreach ($sectionDefs as $key => $label): ?>
    <?php $rows = $sections[$key] ?? []; ?>
    <section class="store-section">
        <h2 class="store-section-title"><?= $label ?></h2>
        <?php if ($rows): ?>
            <div class="store-track">
                <?php foreach ($rows as $p) { $renderCard($p); } ?>
            </div>
        <?php else: ?>
            <div class="store-empty-note muted"><?= $L('store.section_empty') ?></div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if (!empty($creditPackages)): ?>
    <section class="store-section">
        <h2 class="store-section-title"><?= $L('store.credits_title') ?></h2>
        <div class="store-track">
            <?php foreach ($creditPackages as $p): ?>
                <div class="store-card card<?= !empty($p['discount_on']) ? ' store-card-discounted' : '' ?>">
                    <?php if (!empty($p['discount_on'])): ?>
                        <span class="store-discount-badge">-<?= View::e(rtrim(rtrim(number_format((float) $p['discount_percent'], 1, '.', ''), '0'), '.')) ?>%</span>
                    <?php endif; ?>
                    <div class="store-card-head">
                        <div class="store-name"><?= View::e($p['name']) ?></div>
                        <span class="store-type store-type-credits"><?= $L('store.type_credits') ?></span>
                    </div>
                    <div class="store-perks">
                        <span class="badge badge-mute"><?= number_format((int) $p['credits']) ?> <?= $L('credits.credits') ?></span>
                    </div>
                    <?php if (($p['description'] ?? '') !== ''): ?>
                        <p class="muted store-desc"><?= View::e($p['description']) ?></p>
                    <?php endif; ?>

                    <div class="store-price">
                        <div class="store-total">
                            <?php if (!empty($p['discount_on'])): ?>
                                <span class="store-old-price"><?= View::e($p['list_price']) ?></span>
                            <?php endif; ?>
                            <span class="store-current-price"><?= View::e($p['net']) ?> <?= $cur ?></span>
                        </div>
                        <?php if ($passFee): ?>
                            <div class="store-breakdown muted">
                                <?= $L('store.price_with_fee') ?>: <?= View::e($p['total']) ?> <?= $cur ?>
                                <span class="store-fee-part">(+<?= View::e($p['fee']) ?> <?= $cur ?> <?= $L('store.price_fee') ?>)</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="<?= View::e(url('/store/cart/add')) ?>" class="store-buy js-add-cart-form">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="package_id" value="<?= View::e($p['id']) ?>">

                        <label class="check store-gift-toggle">
                            <input type="checkbox" name="is_gift" value="1" class="js-gift-toggle">
                            <span><?= $L('store.gift') ?></span>
                        </label>
                        <div class="field js-gift-field" style="display:none;margin-top:6px">
                            <label><?= $L('store.gift_steamid') ?></label>
                            <input type="text" name="gift_steamid" placeholder="STEAM_0:1:... / 7656119...">
                            <p class="hint" style="margin-top:4px"><?= $L('store.gift_hint') ?></p>
                        </div>

                        <?php if ($loggedIn): ?>
                            <button type="submit" class="btn btn-primary store-buy-btn js-add-cart-btn"><?= $L('store.add_to_cart') ?></button>
                        <?php else: ?>
                            <a href="<?= View::e(url('/auth/steam')) ?>" class="btn btn-primary store-buy-btn"><?= $L('store.add_to_cart') ?></a>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (($donationsOn ?? false)): ?>
    <section class="store-section">
        <h2 class="store-section-title"><?= $L('store.donation') ?></h2>
        <div class="store-track">
            <div class="store-card card donation-card">
                <div class="store-card-head">
                    <div class="store-name"><?= $L('store.donation_title') ?></div>
                </div>
                <p class="muted store-desc"><?= $L('store.donation_hint') ?></p>
                <?php if ($loggedIn): ?>
                    <form method="post" action="<?= View::e(url('/store/donate')) ?>" class="store-buy donation-form">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <div class="field" style="margin:0">
                            <label><?= $L('store.donation_amount') ?> (<?= $cur ?>)</label>
                            <input type="number" name="amount" value="<?= View::e($donationDefault ?? '1.00') ?>" min="<?= View::e($donationMin ?? '1.00') ?>" step="0.01" required>
                        </div>
                        <label class="check store-gift-toggle" style="margin-top:12px">
                            <input type="checkbox" name="cover_fee" value="1">
                            <span><?= $L('store.donation_cover_fee') ?></span>
                        </label>
                        <button type="submit" class="btn btn-primary store-buy-btn"><?= $L('store.donate') ?></button>
                    </form>
                <?php else: ?>
                    <a href="<?= View::e(url('/auth/steam')) ?>" class="btn btn-primary store-buy-btn"><?= $L('auth.login_steam') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- Toast shown after adding to cart -->
<div id="cart-toast" class="cart-toast" role="status" aria-live="polite"></div>

<!-- Blocking pop-up: shown when the beneficiary already has VIP/admin. -->
<div class="modal-overlay store-block-overlay" id="store-block-modal" role="dialog" aria-modal="true">
    <div class="modal store-block-modal">
        <div class="store-block-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h3 class="store-block-title"><?= $L('store.blocked_title') ?></h3>
        <p class="store-block-msg" id="store-block-msg"></p>
        <button type="button" class="btn btn-primary" data-block-close>OK</button>
    </div>
</div>

<script>
// Toggle the gift SteamID field per card.
document.querySelectorAll('.js-gift-toggle').forEach(function (cb) {
    cb.addEventListener('change', function () {
        var field = cb.closest('form').querySelector('.js-gift-field');
        if (field) field.style.display = cb.checked ? '' : 'none';
    });
});

(function () {
    var cartLink = document.getElementById('cart-link');
    var badge = cartLink ? cartLink.querySelector('.cart-count') : null;
    var toast = document.getElementById('cart-toast');
    var toastTimer = null;
    var miniPanel = document.getElementById('mini-cart-panel');
    var miniBody = document.getElementById('mini-cart-body');
    var miniWrap = document.getElementById('mini-cart');

    // Toggle the mini-cart dropdown (don't navigate).
    if (cartLink && miniPanel) {
        cartLink.addEventListener('click', function (e) {
            e.preventDefault();
            miniPanel.hidden = !miniPanel.hidden;
        });
        document.addEventListener('click', function (e) {
            if (miniWrap && !miniWrap.contains(e.target)) miniPanel.hidden = true;
        });
    }
    function refreshMiniCart() {
        if (!miniBody) return;
        fetch((window.CS2_BASE || '') + '/store/cart/mini', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j && j.ok && j.html != null) miniBody.innerHTML = j.html; })
            .catch(function () {});
    }
    var L = {
        added: <?= json_encode(Lang::t('store.added_to_cart')) ?>,
        failed: <?= json_encode(Lang::t('store.order_failed')) ?>,
        csrf: <?= json_encode($csrf) ?>
    };

    // Remove an item straight from the mini-cart (trash button). Delegated
    // because the dropdown HTML is re-rendered after each add/remove.
    if (miniBody) {
        miniBody.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-mini-remove');
            if (!btn) return;
            e.preventDefault();
            btn.disabled = true;
            var body = new URLSearchParams({ csrf: L.csrf, line: btn.getAttribute('data-line') }).toString();
            fetch((window.CS2_BASE || '') + '/store/cart/remove', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
                body: body
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.ok) {
                    if (j.html != null) miniBody.innerHTML = j.html;
                    if (badge) {
                        badge.textContent = j.count;
                        badge.style.display = j.count > 0 ? '' : 'none';
                    }
                } else {
                    btn.disabled = false;
                }
            }).catch(function () { btn.disabled = false; });
        });
    }

    function showToast(msg, ok) {
        if (!toast) return;
        toast.textContent = (ok ? '🛒 ' : '') + msg;
        toast.classList.toggle('is-error', !ok);
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 2600);
    }

    // A bright accent-colored dot that flies from the button to the cart link.
    function flyToCart(fromEl) {
        if (!cartLink || !fromEl) return;
        var a = fromEl.getBoundingClientRect();
        var b = cartLink.getBoundingClientRect();
        var dot = document.createElement('div');
        dot.className = 'cart-fly';
        dot.style.left = (a.left + a.width / 2 - 11) + 'px';
        dot.style.top = (a.top + a.height / 2 - 11) + 'px';
        document.body.appendChild(dot);
        // Force reflow, then animate to the cart link.
        requestAnimationFrame(function () {
            dot.style.transform = 'translate(' + (b.left + b.width / 2 - (a.left + a.width / 2)) + 'px,'
                + (b.top + b.height / 2 - (a.top + a.height / 2)) + 'px) scale(0.35)';
            dot.style.opacity = '0';
        });
        setTimeout(function () {
            dot.remove();
            if (cartLink) { cartLink.classList.remove('cart-bump'); void cartLink.offsetWidth; cartLink.classList.add('cart-bump'); }
        }, 750);
    }

    document.querySelectorAll('.js-add-cart-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('.js-add-cart-btn');
            if (btn) btn.disabled = true;
            var data = new URLSearchParams(new FormData(form)).toString();
            fetch((window.CS2_BASE || '') + '/store/cart/add', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
                body: data
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.ok) {
                    flyToCart(btn);
                    if (badge) { badge.textContent = j.count; badge.style.display = ''; }
                    showToast(j.message || L.added, true);
                    refreshMiniCart();
                    // Reset the gift toggle after a successful add.
                    var g = form.querySelector('.js-gift-toggle'); if (g && g.checked) { g.checked = false; g.dispatchEvent(new Event('change')); }
                } else if (j && j.blocked) {
                    showBlockPopup(j.message || L.failed);
                } else {
                    showToast((j && j.message) ? j.message : L.failed, false);
                }
            }).catch(function () { showToast(L.failed, false); })
              .finally(function () { if (btn) btn.disabled = false; });
        });
    });

    // Blocking pop-up shown when the beneficiary already has VIP/admin.
    var blockOverlay = document.getElementById('store-block-modal');
    var blockMsg = document.getElementById('store-block-msg');
    function showBlockPopup(msg) {
        if (!blockOverlay) { showToast(msg, false); return; }
        if (blockMsg) blockMsg.textContent = msg;
        blockOverlay.classList.add('open');
    }
    if (blockOverlay) {
        blockOverlay.addEventListener('click', function (e) {
            if (e.target === blockOverlay || e.target.closest('[data-block-close]')) {
                blockOverlay.classList.remove('open');
            }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') blockOverlay.classList.remove('open'); });
    }
})();
</script>
