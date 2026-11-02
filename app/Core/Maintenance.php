<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\ContestController;
use App\Models\OrderModel;

/**
 * Centralized periodic maintenance for the whole panel.
 *
 * All background/periodic work lives here in ONE place:
 *   - contests: start scheduled ones that are due, finalize ended ones, purge
 *     old history;
 *   - store: revoke store-granted admin perks whose duration elapsed, and
 *     auto-cancel orders stuck in "pending" past the grace period.
 *
 * Two entry points share this exact logic:
 *   - the token-protected /cron endpoint (ContestController::cron) — for hosts
 *     with a real crontab, called every minute;
 *   - lazyTick() — a throttled, best-effort call made on normal page loads from
 *     the front controller, so the panel still self-maintains when no cron is
 *     configured (at most once per throttle window, never blocking a page).
 */
final class Maintenance
{
    /** Setting key: unix timestamp of the last full pass (cron OR lazy). */
    private const LAST_RUN_KEY = 'last_maintenance_run';

    /** Minimum seconds between two LAZY runs (does not throttle the cron). */
    private const THROTTLE_SECONDS = 60;

    /** Setting key + interval for the weekly skin rarity-colour refresh. */
    private const RARITY_LAST_KEY  = 'last_rarity_colors_refresh';
    private const RARITY_INTERVAL  = 604800; // 7 days (seconds)

    /**
     * Run every maintenance task once and return the counters. Each block is
     * isolated so a failure in one (e.g. contests) never prevents the others
     * (e.g. order cleanup) from running. Never throws.
     *
     * @return array{started:int,processed:int,purged:int,admin_expired:int,orders_cancelled:int}
     */
    public static function run(): array
    {
        $counters = [
            'started' => 0,
            'processed' => 0,
            'purged' => 0,
            'admin_expired' => 0,
            'orders_cancelled' => 0,
            'vip_pending_applied' => 0,
        ];

                                                                     
        try {
            $c = ContestController::runDueProcessing();
            $counters['started']   = $c['started'];
            $counters['processed'] = $c['processed'];
            $counters['purged']    = $c['purged'];
        } catch (\Throwable $e) {
            error_log('[CS2Panel] maintenance contests failed: ' . $e->getMessage());
        }

                                             
        try {
            $counters['admin_expired'] = OrderModel::purgeExpiredAdmins();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] maintenance admin-expiry failed: ' . $e->getMessage());
        }

        // Apply any queued contest VIP prizes whose previous (different-group)
        // VIP has now expired: keep the old VIP until it lapses, then grant the
        // won group for the prize duration (VIPCore holds one row per server).
        try {
            $counters['vip_pending_applied'] = \App\Models\VipPendingModel::applyDue();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] maintenance pending-vip apply failed: ' . $e->getMessage());
        }

                                                                  
        try {
            $counters['orders_cancelled'] = OrderModel::cancelStalePending(Store::pendingCancelMinutes());
        } catch (\Throwable $e) {
            error_log('[CS2Panel] maintenance stale-cancel failed: ' . $e->getMessage());
        }

        // Activity history: record players who became active since the last
        // sample (builds the dashboard entry-history graph over time).
        try {
            \App\Models\ActivityModel::sample();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] maintenance activity sample failed: ' . $e->getMessage());
        }

                                                                               
        // Refresh rarity colors only for enabled Skins, once per interval.
        try {
            if (Settings::bool('skins_enabled', false)) {
                $now  = time();
                $last = (int) Settings::get(self::RARITY_LAST_KEY, '0');
                if ($last === 0 || ($now - $last) >= self::RARITY_INTERVAL) {
                    // Claim the window first so a slow download isn't retried by
                    // the next tick while this one is still running.
                    Settings::set(self::RARITY_LAST_KEY, (string) $now);
                    // Full catalog refresh: item JSONs + rarity colours.
                    \App\Core\WeaponPaintsData::refreshAll();
                }
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] maintenance rarity-colors refresh failed: ' . $e->getMessage());
        }

        // Record when a full pass last completed. This is what lazyTick() checks,
        // so a real cron run keeps the timestamp fresh and page-load ticks stay
        // silent (no redundant work) while the cron handles everything in real
        // time. Best-effort: a failure here must not discard the counters.
        try {
            Settings::set(self::LAST_RUN_KEY, (string) time());
        } catch (\Throwable $e) {
            error_log('[CS2Panel] maintenance timestamp update failed: ' . $e->getMessage());
        }

        return $counters;
    }

    /**
     * Throttled, best-effort maintenance for page loads. Runs run() at most once
     * per throttle window (tracked in the last_maintenance_run setting) so it
     * adds no measurable cost to normal browsing, and swallows every error so it
     * can never break the page being rendered.
     *
     * Idempotent under concurrency: the timestamp is written BEFORE the work runs
     * so a burst of simultaneous requests doesn't all trigger a full pass.
     */
    public static function lazyTick(): void
    {
        try {
            $now  = time();
            $last = (int) Settings::get(self::LAST_RUN_KEY, '0');
            if ($last > 0 && ($now - $last) < self::THROTTLE_SECONDS) {
                return; // ran recently — skip
            }
            // Claim this window immediately so concurrent requests bail out above.
            Settings::set(self::LAST_RUN_KEY, (string) $now);
            self::run();
        } catch (\Throwable $e) {
            error_log('[CS2Panel] maintenance lazyTick failed: ' . $e->getMessage());
        }
    }
}
