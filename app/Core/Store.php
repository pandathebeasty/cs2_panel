<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Store helpers: package configuration + the fee (gross-up) calculator.
 *
 * The owner enters the NET price they want to receive per package. When
 * "pass fee to buyer" is on, the buyer pays the processor fee on top so the
 * owner nets exactly the configured price. The fee model is percent + fixed
 * (e.g. PayPal ~3.4% + €0.35), both editable in Settings.
 *
 * All money math is done in integer minor units (cents) to avoid float error.
 * The grossed-up total is rounded UP to the nearest cent so the owner never
 * ends up short by a cent.
 */
final class Store
{
    /**
     * Test Mode: master switch. When on, the panel exposes test-only helpers
     * (each individually toggleable — see testFeatures()). A panel-wide switch.
     */
    public static function testMode(): bool
    {
        return Settings::bool('test_mode', false);
    }

    /**
     * How long (minutes) an order may sit in "pending" before it's auto-cancelled.
     * A pending order is created-but-never-paid; after this grace period it's
     * expired. Configurable via the 'store_pending_cancel_minutes' setting
     * (default 10, clamped to a sane 1..1440 range).
     */
    public static function pendingCancelMinutes(): int
    {
        $m = (int) Settings::get('store_pending_cancel_minutes', 10);
        if ($m < 1) {
            $m = 10;
        }
        return min(1440, $m);
    }

    /**
     * The individual test features that Test Mode unlocks. Each has a settings
     * key ("test_feat_<id>", default OFF) and label/hint lang keys shown in the
     * Settings UI when Test Mode is enabled. Add new test tools here.
     *
     * @return array<string,array{label:string,hint:string}>
     */
    public static function testFeatures(): array
    {
        return [
            'store_mark_paid' => [
                'label' => 'settings.test_feat_mark_paid',
                'hint'  => 'settings.test_feat_mark_paid_hint',
            ],
            'order_resend_email' => [
                'label' => 'settings.test_feat_resend_email',
                'hint'  => 'settings.test_feat_resend_email_hint',
            ],
            'discord_test_purchase' => [
                'label' => 'settings.test_feat_discord_purchase',
                'hint'  => 'settings.test_feat_discord_purchase_hint',
            ],
        ];
    }

    /**
     * Is a specific test feature usable right now? Requires the master Test Mode
     * to be on AND the per-feature toggle enabled (each defaults OFF, so enabling
     * Test Mode alone activates nothing — you opt into each tool individually).
     */
    public static function testFeatureEnabled(string $id): bool
    {
        if (!self::testMode()) {
            return false;
        }
        if (!isset(self::testFeatures()[$id])) {
            return false;
        }
        return Settings::bool('test_feat_' . $id, false);
    }

    public static function enabled(): bool
    {
        return Settings::bool('store_enabled', false);
    }

    public static function currency(): string
    {
        $c = strtoupper(trim((string) Settings::get('store_currency', 'EUR')));
        return $c !== '' ? $c : 'EUR';
    }

    /** Whether the buyer covers the processor fee (owner nets the package price). */
    public static function passFeeToBuyer(): bool
    {
        return Settings::bool('store_pass_fee_to_buyer', true);
    }

    /** Are buyer-entered discount codes accepted in the cart? */
    public static function discountCodesEnabled(): bool
    {
        return Settings::bool('store_codes_enabled', false);
    }

    /**
     * A blanket percentage taken off every checkout, configured in Settings
     * (e.g. a seasonal sale). 0 when the toggle is off.
     */
    public static function checkoutDiscountPercent(): float
    {
        if (!Settings::bool('store_checkout_discount_enabled', false)) {
            return 0.0;
        }
        return max(0.0, min(100.0, (float) Settings::get('store_checkout_discount_percent', '0')));
    }

    /**
     * Apply the cart-level reductions to a net amount: first the buyer's code,
     * then the site-wide checkout discount. Applying them in sequence keeps the
     * result sane even when both are large.
     */
    public static function applyCartDiscounts(int $netCents, float $codePercent): int
    {
        $net = self::applyDiscount($netCents, max(0.0, min(100.0, $codePercent)));
        return self::applyDiscount($net, self::checkoutDiscountPercent());
    }

    /** Donations section enabled? */
    public static function donationsEnabled(): bool
    {
        return Settings::bool('store_donations_enabled', false);
    }

    /** Default donation amount in cents (prefilled in the input). Min 1 unit. */
    public static function donationDefaultCents(): int
    {
        $c = self::toCents((string) Settings::get('store_donation_default', '1.00'));
        return $c > 0 ? $c : 100;
    }

    /** Minimum accepted donation in cents. */
    public static function donationMinCents(): int
    {
        $c = self::toCents((string) Settings::get('store_donation_min', '1.00'));
        return $c > 0 ? $c : 100;
    }

    /**
     * Fee breakdown for a donation. The buyer chooses whether to cover the fee.
     * If $coverFee is true, the total is grossed up (owner receives the amount);
     * otherwise the buyer pays exactly the amount (fee comes out of it).
     * @return array{net:int,fee:int,total:int} in cents (net = what owner receives)
     */
    public static function donationBreakdown(int $amountCents, bool $coverFee): array
    {
        $amountCents = max(0, $amountCents);
        $percent = self::feePercent();
        $fixed   = self::feeFixedCents();
        if ($coverFee) {
            $denom = 1.0 - $percent;
            if ($denom <= 0.0) {
                return ['net' => $amountCents, 'fee' => 0, 'total' => $amountCents];
            }
            $total = (int) ceil(($amountCents + $fixed) / $denom);
            return ['net' => $amountCents, 'fee' => $total - $amountCents, 'total' => $total];
        }
        // Buyer pays exactly the amount; the fee is taken out of it.
        $fee = (int) ceil($amountCents * $percent) + $fixed;
        return ['net' => max(0, $amountCents - $fee), 'fee' => $fee, 'total' => $amountCents];
    }

    /** Fee percent as a fraction, e.g. 3.4 (%) -> 0.034. */
    public static function feePercent(): float
    {
        return max(0.0, (float) Settings::get('store_fee_percent', '3.4')) / 100.0;
    }

    /** Fixed fee in cents, e.g. "0.35" -> 35. */
    public static function feeFixedCents(): int
    {
        return self::toCents((string) Settings::get('store_fee_fixed', '0.35'));
    }

    public static function paypalMode(): string
    {
        return Settings::get('store_paypal_mode', 'sandbox') === 'live' ? 'live' : 'sandbox';
    }

    /**
     * PayPal credentials are stored per environment (sandbox / live) so you can
     * keep both sets and switch mode without re-entering keys. Getters below
     * return the set for the CURRENT mode, falling back to the legacy single
     * keys (used before this split) so existing installs keep working.
     */
    public static function paypalClientId(?string $mode = null): string
    {
        return self::paypalCred('client_id', $mode);
    }

    public static function paypalSecret(?string $mode = null): string
    {
        return self::paypalCred('secret', $mode);
    }

    public static function paypalWebhookId(?string $mode = null): string
    {
        return self::paypalCred('webhook_id', $mode);
    }

    /**
     * Resolve one PayPal credential for a mode. Credentials are stored strictly
     * per environment (store_paypal_sandbox_* / store_paypal_live_*); the old
     * pre-split single keys (store_paypal_client_id/_secret/_webhook_id) were
     * dropped — migration 014 removes them from the DB.
     */
    private static function paypalCred(string $field, ?string $mode): string
    {
        $mode = $mode === 'live' || $mode === 'sandbox' ? $mode : self::paypalMode();
        return trim((string) Settings::get("store_paypal_{$mode}_{$field}", ''));
    }

    /**
     * Compute the price breakdown for a given NET amount (in cents).
     *
     * gross-up:  total = ceil( (net + feeFixed) / (1 - feePercent) )
     * so that    total - (total*feePercent + feeFixed) >= net  (owner nets `net`).
     *
     * When passFeeToBuyer is off, the buyer simply pays `net` and the owner
     * absorbs the fee (fee is shown for reference).
     *
     * @return array{net:int,fee:int,total:int} all in cents
     */
    public static function priceBreakdown(int $netCents): array
    {
        $netCents = max(0, $netCents);
        $percent  = self::feePercent();
        $fixed    = self::feeFixedCents();

        if (!self::passFeeToBuyer()) {
            // Buyer pays net; the processor still takes a fee from that amount.
            $fee = (int) ceil($netCents * $percent) + $fixed;
            return ['net' => $netCents, 'fee' => $fee, 'total' => $netCents];
        }

        // Gross-up so the owner receives exactly $netCents after the fee.
        // Guard against a percent >= 100% (would divide by <= 0).
        $denom = 1.0 - $percent;
        if ($denom <= 0.0) {
            // Misconfigured fee — fall back to net so we never divide by zero.
            return ['net' => $netCents, 'fee' => 0, 'total' => $netCents];
        }
        $total = (int) ceil(($netCents + $fixed) / $denom);
        $fee   = $total - $netCents;
        return ['net' => $netCents, 'fee' => $fee, 'total' => $total];
    }

    /**
     * Parse a decimal money string ("10", "10.5", "10,50") into integer cents.
     * Never throws; malformed input becomes 0.
     */
    public static function toCents(string $amount): int
    {
        $amount = str_replace([' ', ','], ['', '.'], trim($amount));
        if ($amount === '' || !is_numeric($amount)) {
            return 0;
        }
        // Round to the nearest cent (input may have >2 decimals).
        return (int) round(((float) $amount) * 100);
    }

                                                                              
    public static function formatCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Store-credit packages (schwarper/cs2-store). A buyer pays real money and
     * receives in-game store credits. Decoded + normalized from the
     * store_credit_packages setting. Each package:
     *   id, name, credits (int > 0), net_cents, description, enabled,
     *   discount_percent/from/to, plus derived type='credits', final_cents,
     *   discount_active. Reuses the same discount/fee helpers as VIP packages.
     *
     * @param bool $onlyEnabled when true, drops disabled packages
     * @return array<int,array<string,mixed>>
     */
    public static function creditPackages(bool $onlyEnabled = false): array
    {
        $raw = (string) Settings::get('store_credit_packages', '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name    = trim((string) ($p['name'] ?? ''));
            $credits = max(0, (int) ($p['credits'] ?? 0));
            // A credit package must have a name and grant at least 1 credit.
            if ($name === '' || $credits <= 0) {
                continue;
            }
            $enabled = !empty($p['enabled']);
            if ($onlyEnabled && !$enabled) {
                continue;
            }
            $id = trim((string) ($p['id'] ?? ''));
            if ($id === '') {
                $id = 'cr-' . substr(preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), 0, 36) . '-' . substr(md5($name . $credits), 0, 6);
            }
            $netCents = max(0, (int) ($p['net_cents'] ?? self::toCents((string) ($p['price'] ?? '0'))));
            $discountPercent = max(0.0, min(100.0, (float) ($p['discount_percent'] ?? 0)));
            $discountFrom = trim((string) ($p['discount_from'] ?? ''));
            $discountTo   = trim((string) ($p['discount_to'] ?? ''));

            $pkg = [
                'id'               => $id,
                'name'             => mb_substr($name, 0, 128),
                'credits'          => $credits,
                'net_cents'        => $netCents,
                'description'      => mb_substr(trim((string) ($p['description'] ?? '')), 0, 255),
                'enabled'          => $enabled,
                'discount_percent' => $discountPercent,
                'discount_from'    => $discountFrom,
                'discount_to'      => $discountTo,
                'type'             => 'credits',
            ];
            $pkg['discount_active'] = self::discountActive($pkg);
            $pkg['final_cents']     = $pkg['discount_active']
                ? self::applyDiscount($netCents, $discountPercent)
                : $netCents;
            $out[] = $pkg;
        }
        return $out;
    }

    /** A single enabled credit package by id, or null. */
    public static function creditPackage(string $id): ?array
    {
        foreach (self::creditPackages(true) as $pkg) {
            if ($pkg['id'] === $id) {
                return $pkg;
            }
        }
        return null;
    }

    /**
     * The configured packages, decoded and normalized. Each package:
     *   id, name, vip_group, days, net_cents, description, server_id ('all'|sid), enabled
     * Malformed entries are skipped.
     *
     * @param bool $onlyEnabled when true, drops disabled packages
     * @return array<int, array{id:string,name:string,vip_group:string,days:int,net_cents:int,description:string,server_id:string,enabled:bool}>
     */
    public static function packages(bool $onlyEnabled = false): array
    {
        $raw = (string) Settings::get('store_packages', '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name  = trim((string) ($p['name'] ?? ''));
            $group = trim((string) ($p['vip_group'] ?? ''));
            $adminGroupId = (int) ($p['admin_group_id'] ?? 0);
            // A package must grant at least one of: VIP group, or admin group.
            if ($name === '' || ($group === '' && $adminGroupId <= 0)) {
                continue;
            }
            $enabled = !empty($p['enabled']);
            if ($onlyEnabled && !$enabled) {
                continue;
            }
            $id = trim((string) ($p['id'] ?? ''));
            if ($id === '') {
                // Stable-ish id derived from name if none stored.
                $id = substr(preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), 0, 40) . '-' . substr(md5($name . $group . $adminGroupId), 0, 6);
            }
            $server = trim((string) ($p['server_id'] ?? 'all'));
            $netCents = max(0, (int) ($p['net_cents'] ?? self::toCents((string) ($p['price'] ?? '0'))));
            $discountPercent = max(0.0, min(100.0, (float) ($p['discount_percent'] ?? 0)));
            $discountFrom = trim((string) ($p['discount_from'] ?? ''));
            $discountTo   = trim((string) ($p['discount_to'] ?? ''));

            $pkg = [
                'id'              => $id,
                'name'            => mb_substr($name, 0, 128),
                'vip_group'       => mb_substr($group, 0, 64),
                'days'            => max(0, (int) ($p['days'] ?? 0)),
                'admin_group_id'  => $adminGroupId > 0 ? $adminGroupId : 0,
                'admin_group_name'=> mb_substr(trim((string) ($p['admin_group_name'] ?? '')), 0, 64),
                'admin_immunity'  => max(0, (int) ($p['admin_immunity'] ?? 0)),
                'admin_days'      => max(0, (int) ($p['admin_days'] ?? 0)),
                'net_cents'       => $netCents,
                'description'     => mb_substr(trim((string) ($p['description'] ?? '')), 0, 255),
                'server_id'       => $server === '' ? 'all' : $server,
                'enabled'         => $enabled,
                'discount_percent'=> $discountPercent,
                'discount_from'   => $discountFrom,
                'discount_to'     => $discountTo,
                                                                             
                'benefits_button' => !empty($p['benefits_button']),
                // Optional bonus store credits granted alongside VIP/admin.
                'bonus_credits'   => max(0, (int) ($p['bonus_credits'] ?? 0)),
            ];
            // Derived, computed once here so views/checkout stay consistent.
            $pkg['type']            = self::packageType($pkg);
            $pkg['sort_days']       = self::sortDuration($pkg);
            $pkg['discount_active'] = self::discountActive($pkg);
            $pkg['final_cents']     = self::discountActive($pkg)
                ? self::applyDiscount($netCents, $discountPercent)
                : $netCents;
            $out[] = $pkg;
        }
        return $out;
    }

    /**
     * Classify a package by what it grants: 'vip', 'admin', or 'vip_admin'.
     * @param array<string,mixed> $pkg
     */
    public static function packageType(array $pkg): string
    {
        $hasVip   = trim((string) ($pkg['vip_group'] ?? '')) !== '';
        $hasAdmin = (int) ($pkg['admin_group_id'] ?? 0) > 0;
        if ($hasVip && $hasAdmin) {
            return 'vip_admin';
        }
        if ($hasAdmin) {
            return 'admin';
        }
        return 'vip';
    }

    /**
     * The duration used for sorting the store list (ascending). Uses the VIP
     * days, or the admin days for an admin-only package. Permanent (0) sorts
     * LAST by mapping it to PHP_INT_MAX.
     * @param array<string,mixed> $pkg
     */
    public static function sortDuration(array $pkg): int
    {
        $days = self::packageType($pkg) === 'admin'
            ? (int) ($pkg['admin_days'] ?? 0)
            : (int) ($pkg['days'] ?? 0);
        return $days > 0 ? $days : PHP_INT_MAX;
    }

    /**
     * Is a package's discount currently active? A discount needs a percent > 0
     * and, if a from/to date is set, the current time must be within it. Empty
     * dates mean "no bound on that side".
     * @param array<string,mixed> $pkg
     */
    public static function discountActive(array $pkg): bool
    {
        $percent = (float) ($pkg['discount_percent'] ?? 0);
        if ($percent <= 0) {
            return false;
        }
        $now = time();
        $from = trim((string) ($pkg['discount_from'] ?? ''));
        $to   = trim((string) ($pkg['discount_to'] ?? ''));
        if ($from !== '' && ($ts = strtotime($from)) !== false && $now < $ts) {
            return false;
        }
        if ($to !== '' && ($ts = strtotime($to)) !== false && $now > $ts) {
            return false;
        }
        return true;
    }

                                                                                               
    public static function applyDiscount(int $netCents, float $percent): int
    {
        $percent = max(0.0, min(100.0, $percent));
        return max(0, (int) round($netCents * (1.0 - $percent / 100.0)));
    }

    /**
     * The net price to actually charge for a package right now, in cents
     * (discount applied if active). This is what the fee gross-up runs on.
     * @param array<string,mixed> $pkg
     */
    public static function effectiveNetCents(array $pkg): int
    {
        $net = max(0, (int) ($pkg['net_cents'] ?? 0));
        if (self::discountActive($pkg)) {
            return self::applyDiscount($net, (float) ($pkg['discount_percent'] ?? 0));
        }
        return $net;
    }

    /** Find a single (optionally enabled-only) package by its id. */
    public static function findPackage(string $id, bool $onlyEnabled = true): ?array
    {
        foreach (self::packages($onlyEnabled) as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        // Also resolve store-credit packages by id, so they flow through the
        // same cart (add / resolve / checkout) as VIP/admin packages. They
        // carry type='credits' + a 'credits' amount so callers can branch.
        foreach (self::creditPackages($onlyEnabled) as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /** A short unique order code, e.g. "CS2-8F3A7B". */
    public static function newOrderCode(): string
    {
        return 'CS2-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
