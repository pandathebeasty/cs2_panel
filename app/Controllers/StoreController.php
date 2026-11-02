<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Cart;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Discord;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\PayPal;
use App\Core\Steam;
use App\Core\Store;
use App\Models\OrderModel;

/**
 * Public store: buy VIP packages via PayPal.
 *
 * Flow: /store (pick a package) -> POST /store/checkout (create a pending order
 * + a PayPal order, redirect to PayPal) -> PayPal -> GET /store/return (capture,
 * grant VIP, notify) or GET /store/cancel. A /store/webhook backs up the return
 * capture in case the buyer closes the tab.
 */
final class StoreController extends Controller
{
    /** Store front: list enabled packages with the buyer-facing price. */
    public function index(): void
    {
        // Purchases require a Steam identity; guests see the sign-in prompt.
                                                                          
        Auth::requireSteam();

        if (!Store::enabled()) {
            $this->view('store/disabled', [], 'main');
            return;
        }

        $packages = [];
        foreach (Store::packages(true) as $p) {
            $effective = Store::effectiveNetCents($p); // discount applied if active
            $b = Store::priceBreakdown($effective);
            $p['net']            = Store::formatCents($b['net']);
            $p['fee']            = Store::formatCents($b['fee']);
            $p['total']          = Store::formatCents($b['total']);
            $p['list_price']     = Store::formatCents((int) $p['net_cents']); // pre-discount
            $p['discount_on']    = $p['discount_active'] && $effective < (int) $p['net_cents'];
            $packages[] = $p;
        }
                                                                          
        // Group by duration (permanent last), then by effective price.
        usort($packages, static function ($a, $b) {
            $byDays = ($a['sort_days'] ?? PHP_INT_MAX) <=> ($b['sort_days'] ?? PHP_INT_MAX);
            if ($byDays !== 0) {
                return $byDays;
            }
            return Store::effectiveNetCents($a) <=> Store::effectiveNetCents($b);
        });

        // Group into sections by what each package grants. Empty sections are
        // still rendered (with a "no packages" message) so the layout is stable.
        $sections = ['vip' => [], 'admin' => [], 'vip_admin' => []];
        foreach ($packages as $p) {
            $t = (string) ($p['type'] ?? 'vip');
            $sections[$t][] = $p;
        }

        // Optional result banner after returning from PayPal
        // (/store?order=CODE&status=paid|failed|cancelled).
        $result = null;
        $rCode = trim((string) $this->query('order', ''));
        $rStatus = (string) $this->query('status', '');
        if ($rCode !== '' && in_array($rStatus, ['paid', 'failed', 'cancelled'], true)) {
            $ro = OrderModel::findByCode($rCode);
            // Only show the banner to the buyer of that order (or an admin).
            if ($ro !== null && ((string) $ro['buyer_steamid'] === (string) Auth::steamId() || Auth::canManage())) {
                $result = ['order' => $ro, 'status' => $rStatus];
            }
        }

        // Resolved cart (for the mini-cart dropdown on the cart icon).
        $cart = $this->resolveCart();

        // Store credits (cs2-store): priced cards, cheapest first. Only shown
        // when the credits feature is enabled and the plugin table exists.
        $creditPackages = [];
        if (\App\Models\StoreCreditsModel::enabled()) {
            foreach (Store::creditPackages(true) as $p) {
                $effective = (int) $p['final_cents'];
                $b = Store::priceBreakdown($effective);
                $p['net']         = Store::formatCents($b['net']);
                $p['fee']         = Store::formatCents($b['fee']);
                $p['total']       = Store::formatCents($b['total']);
                $p['list_price']  = Store::formatCents((int) $p['net_cents']);
                $p['discount_on'] = !empty($p['discount_active']) && $effective < (int) $p['net_cents'];
                $creditPackages[] = $p;
            }
            usort($creditPackages, static fn($a, $b) => (int) $a['final_cents'] <=> (int) $b['final_cents']);
        }

        $this->view('store/index', [
            'packages'    => $packages,
            'sections'    => $sections,
            'creditPackages' => $creditPackages,
            'currency'    => Store::currency(),
            'passFee'     => Store::passFeeToBuyer(),
            'loggedIn'    => Auth::check(),
            'me'          => Auth::user(),
            'result'      => $result,
            'cartCount'   => Cart::count(),
            'cartItems'   => $cart['items'],
            'cartTotal'   => $cart['total'],
            'donationsOn' => Store::donationsEnabled(),
            'donationDefault' => Store::formatCents(Store::donationDefaultCents()),
            'donationMin' => Store::formatCents(Store::donationMinCents()),
            'csrf'        => $this->csrfToken(),
        ]);
    }

                                                                        
    // Cart
                                                                        

    /** Add a package to the cart (with an optional gift beneficiary). */
    public function cartAdd(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();
        if (!Store::enabled()) {
            $this->redirect('/store');
        }
        $pkg = Store::findPackage((string) $this->input('package_id', ''), true);
        if ($pkg === null) {
            $this->flash('error', Lang::t('store.pkg_not_found'));
            $this->redirect('/store');
        }
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
        // Optional gift: a beneficiary SteamID (any format, normalized).
        $beneficiary = '';
        if ((bool) $this->input('is_gift', false)) {
            $gift = $this->steamInput('gift_steamid');
            if ($gift === '' || !Steam::isValidSteamId64($gift)) {
                if ($isAjax) {
                    $this->json(['ok' => false, 'message' => Lang::t('store.gift_invalid')]);
                }
                $this->flash('error', Lang::t('store.gift_invalid'));
                $this->redirect('/store');
            }
            $beneficiary = $gift;
        }

        // Blanket protection: the beneficiary (gift target, or the buyer
        // themselves) must not already have VIP/admin. Resolve a display name
        // for the message, then block with a pop-up if ineligible.
        $benForCheck = $beneficiary !== '' ? $beneficiary : (string) Auth::steamId();
        $benName = '';
        if ($beneficiary !== '' && $beneficiary !== (string) Auth::steamId()) {
            $prof = \App\Core\SteamOpenID::fetchProfile($beneficiary);
            $benName = (string) ($prof['name'] ?? '');
        } else {
            $benName = (string) (Auth::user()['name'] ?? '');
        }
        $conflict = $this->packageConflict($pkg, $benForCheck, $benName);
        if ($conflict !== '') {
            if ($isAjax) {
                $this->json(['ok' => false, 'blocked' => true, 'message' => $conflict]);
            }
            $this->flash('error', $conflict);
            $this->redirect('/store');
        }

        Cart::add($pkg['id'], $beneficiary);

        // AJAX add (from the store page): return JSON, stay on the page.
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            $this->json(['ok' => true, 'count' => Cart::count(), 'message' => Lang::t('store.added_to_cart')]);
        }
        $this->flash('success', Lang::t('store.added_to_cart'));
        $this->redirect('/store/cart');
    }

    public function cartRemove(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();
        Cart::remove((string) $this->input('line', ''));

        // AJAX (from the mini-cart trash button): return the refreshed dropdown.
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            $cart = $this->resolveCart();
            $cartItems = $cart['items'];
            $cartTotal = $cart['total'];
            $currency  = Store::currency();
            $csrf      = $this->csrfToken();
            ob_start();
            require BASE_PATH . '/app/Views/store/_minicart.php';
            $html = (string) ob_get_clean();
            $this->json(['ok' => true, 'count' => Cart::count(), 'html' => $html]);
        }
        $this->redirect('/store/cart');
    }

    /** Render the mini-cart dropdown HTML (for AJAX refresh after add-to-cart). */
    public function miniCart(): void
    {
        Auth::requireSteam();
        $cart = $this->resolveCart();
        $cartItems = $cart['items'];
        $cartTotal = $cart['total'];
        $currency  = Store::currency();
        $csrf      = $this->csrfToken();
        ob_start();
        require BASE_PATH . '/app/Views/store/_minicart.php';
        $html = (string) ob_get_clean();
        $this->json(['ok' => true, 'count' => Cart::count(), 'html' => $html]);
    }

    public function cartClear(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();
        Cart::clear();
        $this->redirect('/store/cart');
    }

    /** Attach (or drop) a discount code on the cart. */
    public function cartCode(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();

        if ($this->input('remove')) {
            Cart::clearDiscountCode();
            $this->redirect('/store/cart');
        }
        if (!Store::discountCodesEnabled()) {
            $this->redirect('/store/cart');
        }

        $code = \App\Models\DiscountModel::normalize((string) $this->input('code', ''));
        $row  = \App\Models\DiscountModel::findByCode($code);
        $why  = \App\Models\DiscountModel::rejectReason($row);
        if ($why !== '') {
            Cart::clearDiscountCode();
            $this->flash('error', Lang::t($why));
            $this->redirect('/store/cart');
        }

        Cart::setDiscountCode($code);
        $this->flash('success', Lang::t('store.code_applied', ['percent' => (float) $row['percent']]));
        $this->redirect('/store/cart');
    }

    /**
     * The discount code currently on the cart, re-validated. Returns
     * ['code' => string, 'percent' => float]; percent is 0 when unusable.
     *
     * @return array{code:string,percent:float}
     */
    private function activeCartCode(): array
    {
        $code = Cart::discountCode();
        if ($code === '' || !Store::discountCodesEnabled()) {
            return ['code' => '', 'percent' => 0.0];
        }
        $row = \App\Models\DiscountModel::findByCode($code);
        if (!\App\Models\DiscountModel::isUsable($row)) {
            Cart::clearDiscountCode();
            return ['code' => '', 'percent' => 0.0];
        }
        return ['code' => $code, 'percent' => (float) $row['percent']];
    }

    /** The cart page: resolved items + combined total. */
    public function cart(): void
    {
        Auth::requireSteam();
        if (!Store::enabled()) {
            $this->view('store/disabled', [], 'main');
            return;
        }

        $rows = $this->resolveCart(); // [items, totals] (drops stale lines)
        $this->view('store/cart', [
            'items'      => $rows['items'],
            'totalCents' => $rows['total'],
            'netCents'   => $rows['net'],
            'feeCents'   => $rows['fee'],
            'grossCents' => $rows['gross'],
            'savedCents' => $rows['saved'],
            'codesOn'    => Store::discountCodesEnabled(),
            'code'       => $rows['code'],
            'codePercent' => $rows['code_percent'],
            'checkoutPercent' => Store::checkoutDiscountPercent(),
            'expiresIn'  => Cart::secondsLeft(),
            'ttlMinutes' => Cart::ttlMinutes(),
            'currency'   => Store::currency(),
            'passFee'    => Store::passFeeToBuyer(),
            'me'         => Auth::user(),
            'csrf'       => $this->csrfToken(),
        ]);
    }

    /**
     * Resolve the session cart into display items + summed totals. Skips lines
     * whose package no longer exists / is disabled. Each item carries its
     * effective (discounted) price and beneficiary display info.
     *
     * @return array{items:array<int,array>,net:int,fee:int,total:int,gross:int,saved:int,code:string,code_percent:float}
     */
    private function resolveCart(): array
    {
        $items = [];
        $net = $fee = $total = $gross = 0;
        $buyerSteamId = (string) Auth::steamId();
        $codeInfo = $this->activeCartCode();

        foreach (Cart::items() as $line) {
            $pkg = Store::findPackage((string) $line['package_id'], true);
            if ($pkg === null) {
                continue; // package removed/disabled since it was added
            }
            $listNet = Store::effectiveNetCents($pkg);
            // Cart-level reductions (buyer code + site-wide checkout discount).
            $effNet  = Store::applyCartDiscounts($listNet, $codeInfo['percent']);
            $b = Store::priceBreakdown($effNet);

            $beneficiary = (string) $line['beneficiary'];
            $isGift = $beneficiary !== '' && $beneficiary !== $buyerSteamId;
            $benName = '';
            if ($isGift) {
                $prof = \App\Core\SteamOpenID::fetchProfile($beneficiary);
                $benName = (string) ($prof['name'] ?? '');
            }

            $benSteamId = $isGift ? $beneficiary : $buyerSteamId;
            $items[] = [
                'line'        => (string) $line['line'],
                'pkg'         => $pkg,
                'is_gift'     => $isGift,
                'beneficiary' => $benSteamId,
                'ben_name'    => $benName,
                'net'         => Store::formatCents($b['net']),
                'fee'         => Store::formatCents($b['fee']),
                'total'       => Store::formatCents($b['total']),
                'expires_at'  => (int) $line['expires_at'],
                'discount_on' => ($pkg['discount_active'] && $listNet < (int) $pkg['net_cents']) || $effNet < $listNet,
                'list_price'  => Store::formatCents((int) $pkg['net_cents']),
                // Constant warning if the beneficiary already owns what this
                // package grants (active VIP / same admin group). Empty = ok.
                'conflict'    => $this->packageConflict($pkg, $benSteamId, $benName),
            ];
            $net   += $b['net'];
            $fee   += $b['fee'];
            $total += $b['total'];
            $gross += $listNet;
        }

        return [
            'items'        => $items,
            'net'          => $net,
            'fee'          => $fee,
            'total'        => $total,
            'gross'        => $gross,
            'saved'        => max(0, $gross - $net),
            'code'         => $codeInfo['code'],
            'code_percent' => $codeInfo['percent'],
        ];
    }

    /**
     * Check whether a beneficiary already owns what a package would grant, so
     * we can stop the purchase before taking a payment for something the player
     * already has. Returns a human-readable reason, or '' when there's no
     * conflict.
     *
     * - VIP package: the beneficiary already has an ACTIVE VIP (any server).
     * - Admin package: the beneficiary already holds that exact admin group.
     *
     * @param array<string,mixed> $pkg
     */
    private function packageConflict(array $pkg, string $beneficiary, string $benName): string
    {
        $who    = $benName !== '' ? $benName : $beneficiary;
        $isSelf = $beneficiary === (string) Auth::steamId();

        // Block ONLY when the package grants a perk the player already has of
        // the SAME kind. Cross-buying is allowed: having VIP and buying admin
        // (or vice-versa) is fine. A VIP+admin package is blocked if the player
        // already has EITHER, since it includes both.
        $grantsVip   = trim((string) ($pkg['vip_group'] ?? '')) !== '';
        $grantsAdmin = (int) ($pkg['admin_group_id'] ?? 0) > 0;

        if ($grantsVip) {
            try {
                if (\App\Models\VipModel::hasActiveVip($beneficiary)) {
                    $group = \App\Models\VipModel::activeVipGroup($beneficiary);
                    return Lang::t(
                        $isSelf ? 'store.you_have_vip' : 'store.gift_has_vip',
                        ['name' => $who, 'group' => $group !== '' ? $group : Lang::t('common.none')]
                    );
                }
            } catch (\Throwable $e) {
                // VIP table unreadable — don't block on that alone.
            }
        }

        if ($grantsAdmin) {
            try {
                if (self::hasRealAdmin($beneficiary)) {
                    $admin = \App\Models\AdminModel::findBySteamId($beneficiary);
                    $group = (string) ($admin['group_name'] ?? '');
                    $group = $group !== '' ? \App\Models\GroupModel::displayName($group) : Lang::t('common.none');
                    return Lang::t(
                        $isSelf ? 'store.you_have_admin' : 'store.gift_has_admin',
                        ['name' => $who, 'group' => $group]
                    );
                }
            } catch (\Throwable $e) {
                // sa_admins unreadable — don't block on that alone.
            }
        }

        return '';
    }

    /**
     * True if the SteamID has ANY admin right that counts as a real perk: an
     * admin group, or any flag other than the reserved-slot flag. A player
     * whose ONLY flag is @css/reservation is treated as a normal player (they
     * can still buy). Flags ending in "reservation" (any @prefix) are ignored.
     */
    private static function hasRealAdmin(string $steamId64): bool
    {
        $admin = \App\Models\AdminModel::findBySteamId($steamId64);
        if ($admin === null) {
            return false;
        }
        // A real admin group counts.
        if ((int) ($admin['group_id'] ?? 0) > 0) {
            return true;
        }
        // Any effective flag except the reserved-slot flag counts.
        foreach (\App\Models\AdminModel::effectiveFlags($admin) as $flag) {
            if (!self::isSlotFlag((string) $flag)) {
                return true;
            }
        }
        return false;
    }

    /** The reserved-slot flag (and its common variants) does NOT block buying. */
    private static function isSlotFlag(string $flag): bool
    {
        $f = strtolower(trim($flag));
        return $f === '@css/reservation' || $f === 'reservation' || $f === '@css/res' || str_ends_with($f, '/reservation');
    }

                                                                          
    public function checkout(): void
    {
        // Buying requires a Steam login (so we know who to grant / who bought).
        Auth::requireSteam();
        $this->verifyCsrf();

        if (!Store::enabled()) {
            $this->flash('error', Lang::t('store.disabled'));
            $this->redirect('/store');
        }
        if (!PayPal::configured()) {
            $this->flash('error', Lang::t('store.not_configured'));
            $this->redirect('/store');
        }

        $pkg = Store::findPackage((string) $this->input('package_id', ''), true);
        if ($pkg === null) {
            $this->flash('error', Lang::t('store.pkg_not_found'));
            $this->redirect('/store');
        }

        $buyer = Auth::user();
        $buyerSteamId = (string) Auth::steamId();

        // Gift target (optional). Defaults to the buyer. Any Steam id format
        // is normalized to SteamID64.
        $beneficiary = $buyerSteamId;
        $isGift = (bool) $this->input('is_gift', false);
        if ($isGift) {
            $gift = $this->steamInput('gift_steamid');
            if ($gift === '' || !Steam::isValidSteamId64($gift)) {
                $this->flash('error', Lang::t('store.gift_invalid'));
                $this->redirect('/store');
            }
            $beneficiary = $gift;
        }

                                                                    
        if ($beneficiary === $buyerSteamId) {
            $benName = (string) ($buyer['name'] ?? '');
        } else {
            $prof = \App\Core\SteamOpenID::fetchProfile($beneficiary);
            $benName = (string) ($prof['name'] ?? '');
        }

        // Don't take a payment for something the beneficiary already owns.
        $conflict = $this->packageConflict($pkg, $beneficiary, $benName);
        if ($conflict !== '') {
            $this->flash('error', $conflict);
            $this->redirect('/store');
        }

        // Confirmation email is mandatory for a purchase.
        $email = trim((string) $this->input('email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', Lang::t('store.email_required'));
            $this->redirect('/store');
        }

        // Price on the CURRENT effective net (discount applied if active), so a
        // buyer can never be charged the pre-discount price by tampering.
        $listNet   = (int) $pkg['net_cents'];
        $pkgNet     = Store::effectiveNetCents($pkg);
        // Site-wide checkout discount from Settings (no cart code here).
        $effNet     = Store::applyCartDiscounts($pkgNet, 0.0);
        $discPct    = $listNet > 0 ? round((1 - $effNet / $listNet) * 100, 2) : 0.0;
        $b = Store::priceBreakdown($effNet);
        $currency = Store::currency();
        $code = Store::newOrderCode();

        $orderId = OrderModel::create([
            'code'                 => $code,
            'buyer_steamid'        => $buyerSteamId,
            'buyer_name'           => (string) ($buyer['name'] ?? ''),
            'beneficiary_steamid'  => $beneficiary,
            'beneficiary_name'     => $benName,
            'package_id'           => $pkg['id'],
            'package_name'         => $pkg['name'],
            'vip_group'            => $pkg['vip_group'],
            'vip_days'             => (int) $pkg['days'],
            'admin_group_id'       => (int) ($pkg['admin_group_id'] ?? 0),
            'admin_group_name'     => (string) ($pkg['admin_group_name'] ?? ''),
            'admin_immunity'       => (int) ($pkg['admin_immunity'] ?? 0),
            'admin_days'           => (int) ($pkg['admin_days'] ?? 0),
            'credit_amount'        => (int) ($pkg['bonus_credits'] ?? 0),
            'server_id'            => (string) $pkg['server_id'],
            'currency'             => $currency,
            'buyer_email'          => $email,
            'list_net_cents'       => $listNet,
            'discount_percent'     => $discPct,
            'net_cents'            => $b['net'],
            'fee_cents'            => $b['fee'],
            'total_cents'          => $b['total'],
            'provider'             => 'paypal',
        ]);
        if ($orderId === 0) {
            $this->flash('error', Lang::t('store.order_failed'));
            $this->redirect('/store');
        }

        $base = rtrim((defined('BASE_URL') ? (string) BASE_URL : ''), '/');
        $siteUrl = rtrim((string) \App\Core\Settings::get('website_url', ''), '/');
        // Prefer the configured site URL for absolute return links; else derive.
        $origin = $siteUrl !== '' ? $siteUrl : (($this->isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base);

        $paypal = PayPal::createOrder(
            Store::formatCents($b['total']),
            $currency,
            $code,
            $pkg['name'] . ' (' . Lang::t('store.order') . ' ' . $code . ')',
            $origin . '/store/return?code=' . rawurlencode($code),
            $origin . '/store/cancel?code=' . rawurlencode($code)
        );

        if (!$paypal['ok'] || $paypal['approveUrl'] === '') {
            OrderModel::markCancelled($orderId);
            // Prefer the specific createOrder error, else the auth/lastError reason.
            $reason = $paypal['error'] !== '' ? $paypal['error'] : PayPal::lastError();
            $this->flash('error', Lang::t('store.paypal_failed') . ($reason !== '' ? ' (' . $reason . ')' : ''));
            $this->redirect('/store');
        }

        OrderModel::setProviderOrder($orderId, $paypal['id']);
        // Off to PayPal (absolute URL).
        header('Location: ' . $paypal['approveUrl']);
        exit;
    }

    /**
     * Direct checkout for a STORE CREDITS package (schwarper/cs2-store). Mirrors
     * checkout() but grants in-game credits instead of VIP/admin. Kept separate
     * from the cart so the credits flow stays simple and self-contained.
     */
    public function creditsCheckout(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();

        if (!Store::enabled()) {
            $this->flash('error', Lang::t('store.disabled'));
            $this->redirect('/store');
        }
        if (!\App\Models\StoreCreditsModel::enabled()) {
            $this->flash('error', Lang::t('credits.disabled'));
            $this->redirect('/store');
        }
        if (!PayPal::configured()) {
            $this->flash('error', Lang::t('store.not_configured'));
            $this->redirect('/store');
        }

        $pkg = Store::creditPackage((string) $this->input('package_id', ''));
        if ($pkg === null) {
            $this->flash('error', Lang::t('store.pkg_not_found'));
            $this->redirect('/store');
        }

        $buyer = Auth::user();
        $buyerSteamId = (string) Auth::steamId();

        // Gift target (optional) — credits can be gifted to another SteamID64.
        $beneficiary = $buyerSteamId;
        if ((bool) $this->input('is_gift', false)) {
            $gift = $this->steamInput('gift_steamid');
            if ($gift === '' || !Steam::isValidSteamId64($gift)) {
                $this->flash('error', Lang::t('store.gift_invalid'));
                $this->redirect('/store');
            }
            $beneficiary = $gift;
        }
        if ($beneficiary === $buyerSteamId) {
            $benName = (string) ($buyer['name'] ?? '');
        } else {
            $prof = \App\Core\SteamOpenID::fetchProfile($beneficiary);
            $benName = (string) ($prof['name'] ?? '');
        }

        $email = trim((string) $this->input('email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', Lang::t('store.email_required'));
            $this->redirect('/store');
        }

        $listNet = (int) $pkg['net_cents'];
        $pkgNet  = (int) $pkg['final_cents']; // discount already applied if active
        $effNet  = Store::applyCartDiscounts($pkgNet, 0.0);
        $discPct = $listNet > 0 ? round((1 - $effNet / $listNet) * 100, 2) : 0.0;
        $b = Store::priceBreakdown($effNet);
        $currency = Store::currency();
        $code = Store::newOrderCode();

        $orderId = OrderModel::create([
            'code'                 => $code,
            'buyer_steamid'        => $buyerSteamId,
            'buyer_name'           => (string) ($buyer['name'] ?? ''),
            'beneficiary_steamid'  => $beneficiary,
            'beneficiary_name'     => $benName,
            'package_id'           => $pkg['id'],
            'package_name'         => $pkg['name'],
            'credit_amount'        => (int) $pkg['credits'],
            'server_id'            => 'all',
            'currency'             => $currency,
            'buyer_email'          => $email,
            'list_net_cents'       => $listNet,
            'discount_percent'     => $discPct,
            'net_cents'            => $b['net'],
            'fee_cents'            => $b['fee'],
            'total_cents'          => $b['total'],
            'provider'             => 'paypal',
        ]);
        if ($orderId === 0) {
            $this->flash('error', Lang::t('store.order_failed'));
            $this->redirect('/store');
        }

        $base = rtrim((defined('BASE_URL') ? (string) BASE_URL : ''), '/');
        $siteUrl = rtrim((string) \App\Core\Settings::get('website_url', ''), '/');
        $origin = $siteUrl !== '' ? $siteUrl : (($this->isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base);

        $paypal = PayPal::createOrder(
            Store::formatCents($b['total']),
            $currency,
            $code,
            $pkg['name'] . ' (' . Lang::t('store.order') . ' ' . $code . ')',
            $origin . '/store/return?code=' . rawurlencode($code),
            $origin . '/store/cancel?code=' . rawurlencode($code)
        );

        if (!$paypal['ok'] || $paypal['approveUrl'] === '') {
            OrderModel::markCancelled($orderId);
            $reason = $paypal['error'] !== '' ? $paypal['error'] : PayPal::lastError();
            $this->flash('error', Lang::t('store.paypal_failed') . ($reason !== '' ? ' (' . $reason . ')' : ''));
            $this->redirect('/store');
        }

        OrderModel::setProviderOrder($orderId, $paypal['id']);
        header('Location: ' . $paypal['approveUrl']);
        exit;
    }

    /**
     * Checkout the whole cart as ONE combined PayPal payment. Creates a parent
     * order plus one item per cart line (each with its own beneficiary), then
     * redirects to PayPal for the summed total.
     */
    public function cartCheckout(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();

        if (!Store::enabled()) {
            $this->flash('error', Lang::t('store.disabled'));
            $this->redirect('/store');
        }
        if (!PayPal::configured()) {
            $this->flash('error', Lang::t('store.not_configured'));
            $this->redirect('/store/cart');
        }

        $resolved = $this->resolveCart();
        if (!$resolved['items']) {
            $this->flash('error', Lang::t('store.cart_empty'));
            $this->redirect('/store/cart');
        }

        // Stop if any beneficiary already owns what their item grants.
        foreach ($resolved['items'] as $row) {
            $conflict = $this->packageConflict($row['pkg'], (string) $row['beneficiary'], (string) $row['ben_name']);
            if ($conflict !== '') {
                $this->flash('error', $conflict);
                $this->redirect('/store/cart');
            }
        }

        $buyer = Auth::user();
        $buyerSteamId = (string) Auth::steamId();
        $email = trim((string) $this->input('email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', Lang::t('store.email_required'));
            $this->redirect('/store/cart');
        }

        $currency = Store::currency();
        $code = Store::newOrderCode();
        $itemCount = count($resolved['items']);

        // Parent order: aggregate totals; per-item perks live in the items table.
        // package_name summarises the cart (e.g. "3 items").
        $orderId = OrderModel::create([
            'code'                => $code,
            'buyer_steamid'       => $buyerSteamId,
            'buyer_name'          => (string) ($buyer['name'] ?? ''),
            'beneficiary_steamid' => $buyerSteamId, // parent placeholder; items hold real beneficiaries
            'beneficiary_name'    => (string) ($buyer['name'] ?? ''),
            'package_id'          => 'cart',
            'package_name'        => Lang::t('store.cart_items_n', [$itemCount]),
            'vip_group'           => '', // parent grants nothing directly
            'vip_days'            => 0,
            'admin_group_id'      => 0,
            'server_id'           => 'all',
            'currency'            => $currency,
            'buyer_email'         => $email,
            'discount_code'       => $resolved['code'],
            'list_net_cents'      => $resolved['gross'],
            'discount_percent'    => $resolved['code_percent'],
            'net_cents'           => $resolved['net'],
            'fee_cents'           => $resolved['fee'],
            'total_cents'         => $resolved['total'],
            'provider'            => 'paypal',
        ]);
        if ($orderId === 0) {
            $this->flash('error', Lang::t('store.order_failed'));
            $this->redirect('/store/cart');
        }

        // Persist each cart line as an order item with its own beneficiary + price.
        foreach ($resolved['items'] as $row) {
            $p = $row['pkg'];
            $isCredits = ($p['type'] ?? '') === 'credits';
            // Same pricing path as the parent total, otherwise the items would
            // not add up to what PayPal charges. Credit packages carry their
            // discounted price in final_cents (no VIP effectiveNetCents path).
            $baseNet = $isCredits ? (int) $p['final_cents'] : Store::effectiveNetCents($p);
            $effNet = Store::applyCartDiscounts($baseNet, $resolved['code_percent']);
            $b = Store::priceBreakdown($effNet);
            OrderModel::addItem($orderId, [
                'order_code'          => $code,
                'beneficiary_steamid' => (string) $row['beneficiary'],
                'beneficiary_name'    => (string) $row['ben_name'],
                'package_id'          => (string) $p['id'],
                'package_name'        => (string) $p['name'],
                'vip_group'           => (string) ($p['vip_group'] ?? ''),
                'vip_days'            => (int) ($p['days'] ?? 0),
                'admin_group_id'      => (int) ($p['admin_group_id'] ?? 0),
                'admin_group_name'    => (string) ($p['admin_group_name'] ?? ''),
                'admin_immunity'      => (int) ($p['admin_immunity'] ?? 0),
                'admin_days'          => (int) ($p['admin_days'] ?? 0),
                'credit_amount'       => $isCredits ? (int) $p['credits'] : (int) ($p['bonus_credits'] ?? 0),
                'server_id'           => (string) ($p['server_id'] ?? 'all'),
                'list_net_cents'      => (int) $p['net_cents'],
                'discount_percent'    => (!empty($p['discount_active'])) ? (float) ($p['discount_percent'] ?? 0) : 0.0,
                'net_cents'           => $b['net'],
                'fee_cents'           => $b['fee'],
                'total_cents'         => $b['total'],
            ]);
        }

        $origin = $this->originUrl();
        $paypal = PayPal::createOrder(
            Store::formatCents($resolved['total']),
            $currency,
            $code,
            Lang::t('store.cart_items_n', [$itemCount]) . ' (' . Lang::t('store.order') . ' ' . $code . ')',
            $origin . '/store/return?code=' . rawurlencode($code),
            $origin . '/store/cancel?code=' . rawurlencode($code)
        );
        if (!$paypal['ok'] || $paypal['approveUrl'] === '') {
            OrderModel::markCancelled($orderId);
            $reason = $paypal['error'] !== '' ? $paypal['error'] : PayPal::lastError();
            $this->flash('error', Lang::t('store.paypal_failed') . ($reason !== '' ? ' (' . $reason . ')' : ''));
            $this->redirect('/store/cart');
        }

        OrderModel::setProviderOrder($orderId, $paypal['id']);
        header('Location: ' . $paypal['approveUrl']);
        exit;
    }

    /**
     * A donation: a free-amount PayPal payment with no VIP/admin. The buyer
     * chooses whether to cover the processing fee.
     */
    public function donate(): void
    {
        Auth::requireSteam();
        $this->verifyCsrf();

        if (!Store::enabled() || !Store::donationsEnabled()) {
            $this->flash('error', Lang::t('store.disabled'));
            $this->redirect('/store');
        }
        if (!PayPal::configured()) {
            $this->flash('error', Lang::t('store.not_configured'));
            $this->redirect('/store');
        }

        $amountCents = Store::toCents((string) $this->input('amount', ''));
        if ($amountCents < Store::donationMinCents()) {
            $this->flash('error', Lang::t('store.donation_too_low'));
            $this->redirect('/store');
        }
        $coverFee = (bool) $this->input('cover_fee', false);
        $b = Store::donationBreakdown($amountCents, $coverFee);

        $email = trim((string) $this->input('email', ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        $buyer = Auth::user();
        $buyerSteamId = (string) Auth::steamId();
        $currency = Store::currency();
        $code = Store::newOrderCode();

        $orderId = OrderModel::create([
            'code'                => $code,
            'buyer_steamid'       => $buyerSteamId,
            'buyer_name'          => (string) ($buyer['name'] ?? ''),
            'beneficiary_steamid' => $buyerSteamId,
            'beneficiary_name'    => (string) ($buyer['name'] ?? ''),
            'package_id'          => 'donation',
            'package_name'        => Lang::t('store.donation'),
            'vip_group'           => '',
            'vip_days'            => 0,
            'admin_group_id'      => 0,
            'server_id'           => 'all',
            'currency'            => $currency,
            'buyer_email'         => $email,
            'list_net_cents'      => $b['net'],
            'discount_percent'    => 0,
            'net_cents'           => $b['net'],
            'fee_cents'           => $b['fee'],
            'total_cents'         => $b['total'],
            'provider'            => 'paypal',
        ]);
        if ($orderId === 0) {
            $this->flash('error', Lang::t('store.order_failed'));
            $this->redirect('/store');
        }

        $origin = $this->originUrl();
        $paypal = PayPal::createOrder(
            Store::formatCents($b['total']),
            $currency,
            $code,
            Lang::t('store.donation') . ' (' . Lang::t('store.order') . ' ' . $code . ')',
            $origin . '/store/return?code=' . rawurlencode($code),
            $origin . '/store/cancel?code=' . rawurlencode($code)
        );
        if (!$paypal['ok'] || $paypal['approveUrl'] === '') {
            OrderModel::markCancelled($orderId);
            $reason = $paypal['error'] !== '' ? $paypal['error'] : PayPal::lastError();
            $this->flash('error', Lang::t('store.paypal_failed') . ($reason !== '' ? ' (' . $reason . ')' : ''));
            $this->redirect('/store');
        }

        OrderModel::setProviderOrder($orderId, $paypal['id']);
        header('Location: ' . $paypal['approveUrl']);
        exit;
    }

    /** Absolute origin for PayPal return/cancel URLs. */
    private function originUrl(): string
    {
        $base = rtrim((defined('BASE_URL') ? (string) BASE_URL : ''), '/');
        $siteUrl = rtrim((string) \App\Core\Settings::get('website_url', ''), '/');
        return $siteUrl !== '' ? $siteUrl : (($this->isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base);
    }

    /** PayPal sends the buyer back here after approval — capture + fulfil. */
    public function return(): void
    {
        $code = (string) $this->query('code', '');
        $order = $code !== '' ? OrderModel::findByCode($code) : null;
        if ($order === null) {
            $this->flash('error', Lang::t('store.order_not_found'));
            $this->redirect('/store');
        }

        // Already fulfilled (e.g. webhook beat us to it) — back to the store.
        if ($order['status'] === 'paid') {
            $this->redirect('/store?order=' . rawurlencode($code) . '&status=paid');
        }

        $providerOrderId = (string) ($order['provider_order_id'] ?? '');
        $cap = PayPal::captureOrder($providerOrderId);
        if (!$cap['ok']) {
            $this->redirect('/store?order=' . rawurlencode($code) . '&status=failed');
        }

        $this->fulfil($order, $cap['captureId']);
        // The cart's items are now on the paid order — empty it.
        Cart::clear();
        $this->redirect('/store?order=' . rawurlencode($code) . '&status=paid');
    }

    /** Buyer cancelled at PayPal. */
    public function cancel(): void
    {
        $code = (string) $this->query('code', '');
        $order = $code !== '' ? OrderModel::findByCode($code) : null;
        if ($order !== null && $order['status'] === 'pending') {
            OrderModel::markCancelled((int) $order['id']);
        }
        $this->redirect('/store?order=' . rawurlencode($code) . '&status=cancelled');
    }

    /**
     * PayPal webhook (server-to-server). No CSRF, no auth. We verify the
     * signature when a webhook id is configured; otherwise we fall back to
     * asking PayPal for the order status directly (never trust the raw body).
     * Idempotent: a paid order is not fulfilled twice.
     */
    public function webhook(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            $this->json(['ok' => false], 400);
        }

        $headers = $this->requestHeaders();
        $verified = PayPal::verifyWebhook($headers, $raw);

        // Find the related order from the resource (order id or capture).
        $resource = $event['resource'] ?? [];
        $providerOrderId = (string) ($resource['id'] ?? '');
        // For capture events the order id is under supplementary_data.
        $supp = $resource['supplementary_data']['related_ids']['order_id'] ?? '';
        if ($supp !== '') {
            $providerOrderId = (string) $supp;
        }

        $order = $providerOrderId !== '' ? OrderModel::findByProviderOrder($providerOrderId) : null;
        if ($order === null) {
            // Nothing we can match — acknowledge so PayPal stops retrying.
            $this->json(['ok' => true]);
        }
        if ($order['status'] === 'paid') {
            $this->json(['ok' => true]); // already handled
        }

        // If we could not verify by signature, confirm the order really is
        // completed by asking PayPal directly before granting anything.
        if (!$verified) {
            $chk = PayPal::getOrder((string) $order['provider_order_id']);
            if (!($chk['ok'] && $chk['status'] === 'COMPLETED')) {
                // Not actually paid (or unverifiable) — do not fulfil.
                $this->json(['ok' => true]);
            }
        }

        // Capture id from the event if present, else empty (getOrder path).
        $captureId = '';
        if (($event['event_type'] ?? '') === 'PAYMENT.CAPTURE.COMPLETED') {
            $captureId = (string) ($resource['id'] ?? '');
        }
        $this->fulfil($order, $captureId);
        $this->json(['ok' => true]);
    }

    /** The signed-in buyer's own purchase history. */
    public function mine(): void
    {
        Auth::requireSteam();
        if (!Store::enabled()) {
            $this->view('store/disabled', [], 'main');
            return;
        }
        $orders = OrderModel::paginate(
            ['status' => (string) $this->query('status', '')],
            $this->page(),
            (int) Config::get('app.per_page', 25),
            (string) Auth::steamId()
        );
        // All items for the visible orders in one query (avoids N+1 in the view).
        $itemsByOrder = OrderModel::itemsForOrders(array_map(static fn($o) => (int) $o['id'], $orders->items));

        $steamIds = [];
        foreach ($orders->items as $o) {
            $steamIds[] = (string) ($o['beneficiary_steamid'] ?? '');
        }
        foreach ($itemsByOrder as $rows) {
            foreach ($rows as $oi) {
                $steamIds[] = (string) ($oi['beneficiary_steamid'] ?? '');
            }
        }
        $steamIds = array_values(array_unique(array_filter($steamIds, [Steam::class, 'isValidSteamId64'])));

        $this->view('store/mine', [
            'orders'       => $orders,
            'itemsByOrder' => $itemsByOrder,
            'avatars'      => $steamIds ? Steam::avatars($steamIds) : [],
            'csrf'         => $this->csrfToken(),
        ]);
    }

                                                                        
    // Internals
                                                                        

    /**
     * Mark an order paid, grant the VIP, and notify. Guarded so it only runs
     * once (the caller already checks status !== 'paid', but re-check for races).
     */
    private function fulfil(array $order, string $captureId): void
    {
        $id = (int) $order['id'];
        $fresh = OrderModel::find($id);
        if ($fresh === null || $fresh['status'] === 'paid') {
            return; // already fulfilled
        }

        OrderModel::markPaid($id, $captureId);

        // A redeemed code only counts once the money actually arrived.
        $usedCode = trim((string) ($fresh['discount_code'] ?? ''));
        if ($usedCode !== '') {
            \App\Models\DiscountModel::markUsed($usedCode);
        }

        $items = OrderModel::items($id);
        if ($items) {
            // Multi-item (cart) order: grant each item to its own beneficiary.
            foreach ($items as $it) {
                try {
                    if (OrderModel::grantVip($it)) {
                        OrderModel::markItemVipGranted((int) $it['id']);
                    }
                } catch (\Throwable $e) {
                    error_log('[CS2Panel] store fulfil item VIP grant error: ' . $e->getMessage());
                }
                try {
                    if (OrderModel::grantAdmin($it)) {
                        OrderModel::markItemAdminGranted((int) $it['id'], OrderModel::adminExpiryFor($it));
                    }
                } catch (\Throwable $e) {
                    error_log('[CS2Panel] store fulfil item admin grant error: ' . $e->getMessage());
                }
                try {
                    if ((int) ($it['credit_amount'] ?? 0) > 0 && empty($it['credits_granted'])) {
                        OrderModel::grantCreditsItem($it);
                    }
                } catch (\Throwable $e) {
                    error_log('[CS2Panel] store fulfil item credits grant error: ' . $e->getMessage());
                }
            }
        } else {
            // Legacy single-item order (perks stored on the order row).
            try {
                if (OrderModel::grantVip($fresh)) {
                    OrderModel::markVipGranted($id);
                }
            } catch (\Throwable $e) {
                error_log('[CS2Panel] store fulfil VIP grant error: ' . $e->getMessage());
            }
            try {
                if (OrderModel::grantAdmin($fresh)) {
                    \App\Core\Database::run(
                        'UPDATE ' . OrderModel::TABLE . ' SET admin_granted = 1, admin_expires_at = ? WHERE id = ?',
                        [OrderModel::adminExpiryFor($fresh), $id]
                    );
                }
            } catch (\Throwable $e) {
                error_log('[CS2Panel] store fulfil admin grant error: ' . $e->getMessage());
            }
        }
        // Store credits (cs2-store): grant on the order row. Idempotent via
        // credits_granted so webhook + return can't double-credit.
        try {
            if ((int) ($fresh['credit_amount'] ?? 0) > 0 && empty($fresh['credits_granted'])) {
                OrderModel::grantCredits($fresh);
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] store fulfil credits grant error: ' . $e->getMessage());
        }
        // Re-read so the notification reflects what was actually granted.
        $fresh = OrderModel::find($id) ?? $fresh;

        $total = Store::formatCents((int) $fresh['total_cents']) . ' ' . (string) $fresh['currency'];

        // Discord purchase notification (who/what/how much/for whom).
        self::sendPurchaseNotification($fresh);

        // Log details: package, net + total (both), and the beneficiary if gift.
        $netStr = Store::formatCents((int) $fresh['net_cents']) . ' ' . (string) $fresh['currency'];
        $logDetail = (string) $fresh['package_name'] . ' — ' . Lang::t('store.price_net') . ': ' . $netStr
            . ' · ' . Lang::t('store.amount') . ': ' . $total;
        if ((string) $fresh['beneficiary_steamid'] !== (string) $fresh['buyer_steamid']) {
            $ben = (string) ($fresh['beneficiary_name'] ?? '') !== '' ? (string) $fresh['beneficiary_name'] : (string) $fresh['beneficiary_steamid'];
            $logDetail .= ' · ' . Lang::t('store.gift_to') . ': ' . $ben;
        }
        Logger::log('store.purchase', (string) $fresh['buyer_steamid'], $logDetail);

        // Optional confirmation email (only if the buyer provided one).
        $email = trim((string) ($fresh['buyer_email'] ?? ''));
        if ($email !== '') {
            try {
                self::sendOrderEmail($fresh, $email, $total);
            } catch (\Throwable $e) {
                error_log('[CS2Panel] store confirmation email error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Build + send the Discord "store purchase" embed for an order. Public +
     * static so the Orders admin page can re-send it as a test. Best-effort.
     */
    public static function sendPurchaseNotification(array $order, bool $testNotification = false): void
    {
        if (!Discord::enabled()) return;
        if (!$testNotification && (string) ($order['status'] ?? '') !== 'paid') {
            error_log('[CS2Panel] Discord: purchase notification skipped because the stored order is not paid.');
            return;
        }
        $simulated = str_starts_with((string) ($order['provider_capture_id'] ?? ''), 'TEST-');
        $id       = (int) $order['id'];
        $items    = OrderModel::items($id);
        $currency = (string) $order['currency'];
        $netStr   = Store::formatCents((int) $order['net_cents']) . ' ' . $currency;
        $feeStr   = Store::formatCents((int) $order['fee_cents']) . ' ' . $currency;
        $total    = Store::formatCents((int) $order['total_cents']) . ' ' . $currency;
        $fields = [
            'store.order' => (string) $order['code'],
            'discord.payment_status' => $simulated ? Lang::t('discord.simulated_payment') : (string) $order['status'],
            'store.package' => (string) $order['package_name'],
        ];

        if ($items) {
            foreach ($items as $n => $it) {
                $parts = [];
                if ((string) ($it['vip_group'] ?? '') !== '') {
                    $vd = (int) $it['vip_days'];
                    $parts[] = (string) $it['vip_group'] . ' · ' . ($vd > 0 ? Lang::t('discord.days_n', [$vd]) : Lang::t('discord.permanent'));
                }
                if ((int) ($it['admin_group_id'] ?? 0) > 0) {
                    $ad = (int) $it['admin_days'];
                    $parts[] = (string) ($it['admin_group_name'] ?? '') . ' (' . Lang::t('store.admin') . ') · ' . ($ad > 0 ? Lang::t('discord.days_n', [$ad]) : Lang::t('discord.permanent'));
                }
                $who = ((string) $it['beneficiary_steamid'] !== (string) $order['buyer_steamid'])
                    ? ' → ' . ((string) ($it['beneficiary_name'] ?? '') !== '' ? $it['beneficiary_name'] : $it['beneficiary_steamid'])
                    : '';
                $fields['#' . ($n + 1) . ' ' . (string) $it['package_name']] = implode(' + ', $parts) . $who;
                $applied = [];
                if ((string) ($it['vip_group'] ?? '') !== '') {
                    $applied[] = Lang::t('store.vip') . ': ' . Lang::t(!empty($it['vip_granted']) ? 'discord.granted' : 'discord.not_granted');
                }
                if ((int) ($it['admin_group_id'] ?? 0) > 0) {
                    $applied[] = Lang::t('store.admin') . ': ' . Lang::t(!empty($it['admin_granted']) ? 'discord.granted' : 'discord.not_granted');
                }
                if ($applied) $fields['#' . ($n + 1) . ' ' . (string) $it['package_name']] .= "\n" . implode("\n", $applied);
            }
        } else {
            if ((string) ($order['vip_group'] ?? '') !== '') {
                $vdays = (int) $order['vip_days'];
                $vdur = $vdays > 0 ? Lang::t('discord.days_n', [$vdays]) : Lang::t('discord.permanent');
                $fields['store.vip'] = (string) $order['vip_group'] . ' · ' . $vdur;
                $fields['discord.vip_grant'] = Lang::t(!empty($order['vip_granted']) ? 'discord.granted' : 'discord.not_granted');
            }
            if ((int) ($order['admin_group_id'] ?? 0) > 0) {
                $adays = (int) $order['admin_days'];
                $adur = $adays > 0 ? Lang::t('discord.days_n', [$adays]) : Lang::t('discord.permanent');
                $fields['store.admin'] = (string) ($order['admin_group_name'] ?? '') . ' · ' . $adur;
                $fields['discord.admin_grant'] = Lang::t(!empty($order['admin_granted']) ? 'discord.granted' : 'discord.not_granted');
            }
            if ((string) $order['beneficiary_steamid'] !== (string) $order['buyer_steamid']) {
                $fields['store.gift_to'] = ((string) $order['beneficiary_name'] !== '' ? $order['beneficiary_name'] . ' · ' : '') . (string) $order['beneficiary_steamid'];
            }
        }
        $fields['store.price_net'] = $netStr;
        $fields['discord.processing_charge'] = $feeStr;
        $fields['store.amount']    = $total;
        $fields['store.order']     = (string) $order['code'];

        $buyerAvatar = '';
        try {
            $av = Steam::avatars([(string) $order['buyer_steamid']]);
            $buyerAvatar = (string) ($av[(string) $order['buyer_steamid']] ?? '');
        } catch (\Throwable $e) {
            // no avatar — embed still sends without a thumbnail
        }

        try {
            Discord::notify(
                'store.purchase_title',
                $simulated ? Lang::t('discord.simulated_payment') : 'store.act_purchase',
                'vip',
                $fields,
                [
                    'name'    => (string) ($order['buyer_name'] ?? '') !== '' ? (string) $order['buyer_name'] : Lang::t('discord.unknown'),
                    'steamid' => (string) $order['buyer_steamid'],
                    'avatar'  => $buyerAvatar,
                    'test' => $testNotification || $simulated,
                    'actor' => $testNotification || $simulated
                        ? (string) (Auth::user()['name'] ?? Lang::t('discord.actor_unknown'))
                        : Lang::t('discord.payment_processor'),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] store Discord notify error: ' . $e->getMessage());
        }
    }

    /** Build + send the order confirmation email (plain text). Public + static
     *  so the Orders admin page can re-send it as a test. */
    public static function sendOrderEmail(array $order, string $email, string $total): void
    {
        $site = (string) \App\Core\Settings::get('site_name', 'CS2 Panel');
        $lines = [];
        $lines[] = Lang::t('store.email_greeting');
        $lines[] = '';
        $lines[] = Lang::t('store.order') . ': ' . (string) $order['code'];
        $items = OrderModel::items((int) $order['id']);
        if ($items) {
            foreach ($items as $n => $it) {
                $parts = [];
                if ((string) ($it['vip_group'] ?? '') !== '') {
                    $vd = (int) $it['vip_days'];
                    $parts[] = (string) $it['vip_group'] . ' · ' . ($vd > 0 ? Lang::t('discord.days_n', [$vd]) : Lang::t('discord.permanent'));
                }
                if ((int) ($it['admin_group_id'] ?? 0) > 0) {
                    $ad = (int) $it['admin_days'];
                    $parts[] = (string) ($it['admin_group_name'] ?? '') . ' (' . Lang::t('store.admin') . ') · ' . ($ad > 0 ? Lang::t('discord.days_n', [$ad]) : Lang::t('discord.permanent'));
                }
                $who = ((string) $it['beneficiary_steamid'] !== (string) $order['buyer_steamid'])
                    ? ' → ' . ((string) ($it['beneficiary_name'] ?? '') !== '' ? $it['beneficiary_name'] : $it['beneficiary_steamid'])
                    : '';
                $lines[] = '#' . ($n + 1) . ' ' . (string) $it['package_name'] . ': ' . implode(' + ', $parts) . $who;
            }
        } else {
            $lines[] = Lang::t('store.package') . ': ' . (string) $order['package_name'];
            if ((string) ($order['vip_group'] ?? '') !== '') {
                $vd = (int) $order['vip_days'];
                $lines[] = Lang::t('store.vip') . ': ' . (string) $order['vip_group'] . ' · '
                    . ($vd > 0 ? Lang::t('discord.days_n', [$vd]) : Lang::t('discord.permanent'));
            }
            if ((int) ($order['admin_group_id'] ?? 0) > 0) {
                $ad = (int) $order['admin_days'];
                $lines[] = Lang::t('store.admin') . ': ' . (string) ($order['admin_group_name'] ?? '') . ' · '
                    . ($ad > 0 ? Lang::t('discord.days_n', [$ad]) : Lang::t('discord.permanent'));
            }
        }
        $lines[] = Lang::t('store.amount') . ': ' . $total;
        $lines[] = '';
        $lines[] = Lang::t('store.email_footer', ['site' => $site]);

        \App\Core\Mailer::send(
            $email,
            Lang::t('store.email_subject', ['site' => $site, 'code' => (string) $order['code']]),
            implode("\n", $lines)
        );
    }

    /** Best-effort HTTPS detection (behind proxies too). */
    private function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** @return array<string,string> request headers (getallheaders fallback). */
    private function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (is_array($h)) {
                return $h;
            }
        }
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with((string) $k, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $k, 5)))));
                $out[$name] = (string) $v;
            }
        }
        return $out;
    }
}
