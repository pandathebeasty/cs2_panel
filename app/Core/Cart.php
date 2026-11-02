<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session-backed shopping cart for the store. Each line item references a
 * package id and an optional beneficiary (own account or a gift SteamID64).
 * The same package can appear multiple times (e.g. one for you, one as a gift).
 *
 * Lines expire on their own a few minutes after being added (see ttlMinutes),
 * so an abandoned cart doesn't hold a discount code or a stale price forever.
 *
 * Stored in $_SESSION['cart'] as a list of:
 *   ['line' => string, 'package_id' => string, 'beneficiary' => string(''=self),
 *    'added_at' => int]
 */
final class Cart
{
    private const KEY = 'cart';

    /** How long a cart line survives, in minutes. */
    public static function ttlMinutes(): int
    {
        return max(1, min(1440, (int) Settings::get('cart_ttl_minutes', 10)));
    }

    /** @return array<int, array{line:string,package_id:string,beneficiary:string,added_at:int,expires_at:int}> */
    public static function items(): array
    {
        self::purgeExpired();
        $items = $_SESSION[self::KEY] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $ttl = self::ttlMinutes() * 60;
        return array_values(array_map(static function (array $it) use ($ttl): array {
            $added = (int) ($it['added_at'] ?? time());
            $it['added_at']   = $added;
            $it['expires_at'] = $added + $ttl;
            return $it;
        }, $items));
    }

    public static function count(): int
    {
        return count(self::items());
    }

    /** Drop every line that has outlived the TTL. */
    public static function purgeExpired(): void
    {
        $items = $_SESSION[self::KEY] ?? [];
        if (!is_array($items) || !$items) {
            return;
        }
        $cutoff = time() - self::ttlMinutes() * 60;
        $kept = array_values(array_filter(
            $items,
            static fn($it) => (int) ($it['added_at'] ?? 0) > $cutoff
        ));
        if (count($kept) !== count($items)) {
            $_SESSION[self::KEY] = $kept;
            if (!$kept) {
                unset($_SESSION['cart_discount']);
            }
        }
    }

    /**
     * When the cart as a whole stops being valid: the earliest line expiry.
     * 0 when the cart is empty.
     */
    public static function expiresAt(): int
    {
        $times = array_column(self::items(), 'expires_at');
        return $times ? min($times) : 0;
    }

    /** Seconds left until the first line expires (0 when empty/expired). */
    public static function secondsLeft(): int
    {
        $at = self::expiresAt();
        return $at > 0 ? max(0, $at - time()) : 0;
    }

    /**
     * Add a package to the cart. $beneficiary is a SteamID64 for a gift, or ''
     * to grant to the buyer themselves. Returns the new line id.
     */
    public static function add(string $packageId, string $beneficiary = ''): string
    {
        $items = self::items();
        $line = bin2hex(random_bytes(4));
        $items[] = [
            'line'        => $line,
            'package_id'  => $packageId,
            'beneficiary' => $beneficiary,
            'added_at'    => time(),
        ];
        $_SESSION[self::KEY] = $items;
        return $line;
    }

                                          
    public static function remove(string $line): void
    {
        $items = array_values(array_filter(self::items(), static fn($it) => $it['line'] !== $line));
        $_SESSION[self::KEY] = $items;
        if (!$items) {
            unset($_SESSION['cart_discount']);
        }
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY], $_SESSION['cart_discount']);
    }

                                                 

    /** The discount code currently applied to the cart ('' when none). */
    public static function discountCode(): string
    {
        self::purgeExpired();
        return (string) ($_SESSION['cart_discount'] ?? '');
    }

    public static function setDiscountCode(string $code): void
    {
        $_SESSION['cart_discount'] = $code;
    }

    public static function clearDiscountCode(): void
    {
        unset($_SESSION['cart_discount']);
    }
}
