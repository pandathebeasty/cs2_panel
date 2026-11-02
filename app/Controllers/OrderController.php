<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
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
 * Admin view of all store orders, plus root-only refund/re-grant actions.
 */
final class OrderController extends Controller
{
    public function index(): void
    {
        Auth::requireManage();

        // Note: stale-pending cancellation now runs centrally via
        // Maintenance::lazyTick() (front controller), not per-page here.

        $filters = [
            'status' => (string) $this->query('status', ''),
            'search' => trim((string) $this->query('search', '')),
        ];
        $orders = OrderModel::paginate($filters, $this->page(), (int) Config::get('app.per_page', 25));

        // All items for the visible orders in ONE query (avoids N+1), reused for
        // both the avatar batch and rendering (passed to the view).
        $itemsByOrder = OrderModel::itemsForOrders(array_map(static fn($o) => (int) $o['id'], $orders->items));

        // Steam avatars for buyers + beneficiaries on this page (batch), incl.
        // per-item beneficiaries on multi-item cart orders.
        $steamIds = [];
        foreach ($orders->items as $o) {
            foreach ([(string) $o['buyer_steamid'], (string) $o['beneficiary_steamid']] as $sid) {
                if (Steam::isValidSteamId64($sid)) {
                    $steamIds[$sid] = true;
                }
            }
            foreach (($itemsByOrder[(int) $o['id']] ?? []) as $it) {
                $bid = (string) ($it['beneficiary_steamid'] ?? '');
                if (Steam::isValidSteamId64($bid)) {
                    $steamIds[$bid] = true;
                }
            }
        }
        $avatars = $steamIds ? Steam::avatars(array_keys($steamIds)) : [];

        $this->view('orders/index', [
            'orders'     => $orders,
            'filters'    => $filters,
            'avatars'    => $avatars,
            'itemsByOrder' => $itemsByOrder,
            'revenueNet' => OrderModel::paidNetTotalCents(),
            'currency'   => Store::currency(),
            'isRoot'     => Auth::isRoot(),
            'testMode'   => Store::testFeatureEnabled('store_mark_paid'),
            'testFeat'   => [
                'mark_paid'       => Store::testFeatureEnabled('store_mark_paid'),
                'resend_email'    => Store::testFeatureEnabled('order_resend_email'),
                'discord_purchase'=> Store::testFeatureEnabled('discord_test_purchase'),
            ],
            'csrf'       => $this->csrfToken(),
        ]);
    }

    /**
     * Refund an order via PayPal and revoke the granted VIP. Root only.
     */
    public function refund(string $id): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        $order = OrderModel::find((int) $id);
        if ($order === null) {
            $this->flash('error', Lang::t('store.order_not_found'));
            $this->redirect('/orders');
        }
        if ($order['status'] !== 'paid') {
            $this->flash('error', Lang::t('orders.refund_failed') . ' — ' . strtoupper((string) $order['status']));
            $this->redirect('/orders');
        }

        $captureId = (string) ($order['provider_capture_id'] ?? '');
        $currency  = (string) $order['currency'];
        $amount    = Store::formatCents((int) $order['total_cents']);

        $res = PayPal::refund($captureId, $amount, $currency);
        if (!$res['ok']) {
            $this->flash('error', Lang::t('orders.refund_failed') . ($res['error'] !== '' ? ' (' . $res['error'] . ')' : ''));
            $this->redirect('/orders');
        }

        // Revoke everything this order granted (VIP + admin), then mark refunded.
        try {
            $items = OrderModel::items((int) $id);
            if ($items) {
                // Multi-item (cart) order: revoke each item's perks.
                foreach ($items as $it) {
                    OrderModel::revokeVip($it);
                    OrderModel::revokeAdmin($it);
                }
            } else {
                // Single-item order (perks stored on the order row).
                OrderModel::revokeVip($order);
                OrderModel::revokeAdmin($order);
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] order refund revoke error: ' . $e->getMessage());
        }
        OrderModel::markRefunded((int) $id);

        $total = $amount . ' ' . $currency;
        try {
            Discord::notify(
                'store.refund_title',
                'store.act_refund',
                'reset',
                [
                    'store.order'   => (string) $order['code'],
                    'store.package' => (string) $order['package_name'],
                    'store.amount'  => $total,
                ],
                ['name' => (string) ($order['buyer_name'] ?? 'Unknown'), 'steamid' => (string) $order['buyer_steamid']]
            );
        } catch (\Throwable $e) {
            error_log('[CS2Panel] order refund Discord error: ' . $e->getMessage());
        }
        Logger::log('store.refund', (string) $order['buyer_steamid'], $order['code'] . ' → ' . $total);

        $this->flash('success', Lang::t('orders.refunded'));
        $this->redirect('/orders');
    }

    /**
     * Re-grant the VIP for a paid order (e.g. if the initial grant failed
     * because VIPCore was down). Root only.
     */
    public function regrant(string $id): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        $order = OrderModel::find((int) $id);
        if ($order === null) {
            $this->flash('error', Lang::t('store.order_not_found'));
            $this->redirect('/orders');
        }
        if ($order['status'] !== 'paid') {
            $this->flash('error', Lang::t('orders.refund_failed') . ' — ' . strtoupper((string) $order['status']));
            $this->redirect('/orders');
        }

        $granted = $this->grantOrder((int) $id, $order);
        Logger::log('store.regrant', (string) $order['beneficiary_steamid'], (string) $order['code']);
        $this->flash($granted ? 'success' : 'error', Lang::t($granted ? 'orders.regranted' : 'orders.refund_failed'));
        $this->redirect('/orders');
    }

    /**
     * TEST ONLY: mark a PENDING order as paid and run the grants, exactly as a
     * real PayPal capture/webhook would — so you can verify the full DB flow
     * (order row -> paid, items, VIP/admin grants) WITHOUT a real payment.
     * Owner only. Refuses to touch an already-paid/refunded order.
     */
    public function markPaidTest(string $id): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();

        // Test-only action: refuse unless Test Mode + this feature are enabled.
        if (!Store::testFeatureEnabled('store_mark_paid')) {
            $this->flash('error', Lang::t('orders.test_mode_off'));
            $this->redirect('/orders');
        }

        $order = OrderModel::find((int) $id);
        if ($order === null) {
            $this->flash('error', Lang::t('store.order_not_found'));
            $this->redirect('/orders');
        }
        if ($order['status'] !== 'pending') {
            $this->flash('error', Lang::t('orders.test_not_pending') . ' — ' . strtoupper((string) $order['status']));
            $this->redirect('/orders');
        }

        // Mark paid with a synthetic capture id so it's obviously a test.
        OrderModel::markPaid((int) $id, 'TEST-' . strtoupper(bin2hex(random_bytes(4))));
        $this->grantOrder((int) $id, $order);

        Logger::log('store.test_paid', (string) $order['beneficiary_steamid'], (string) $order['code']);
        $this->flash('success', Lang::t('orders.test_paid_ok') . ' ' . (string) $order['code']);
        $this->redirect('/orders');
    }

    /**
     * TEST ONLY: re-send the confirmation email for an order (uses the buyer
     * email stored on the order). Owner + feature gated.
     */
    public function resendEmail(string $id): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        if (!Store::testFeatureEnabled('order_resend_email')) {
            $this->flash('error', Lang::t('orders.test_mode_off'));
            $this->redirect('/orders');
        }
        $order = OrderModel::find((int) $id);
        if ($order === null) {
            $this->flash('error', Lang::t('store.order_not_found'));
            $this->redirect('/orders');
        }
        $email = trim((string) ($order['buyer_email'] ?? ''));
        if ($email === '') {
            $this->flash('error', Lang::t('orders.test_no_email'));
            $this->redirect('/orders');
        }
        $total = Store::formatCents((int) $order['total_cents']) . ' ' . (string) $order['currency'];
        try {
            \App\Controllers\StoreController::sendOrderEmail($order, $email, $total);
            $this->flash('success', Lang::t('orders.test_email_sent') . ' ' . $email);
        } catch (\Throwable $e) {
            $this->flash('error', Lang::t('orders.test_email_failed'));
        }
        Logger::log('store.test_resend_email', (string) $order['buyer_steamid'], (string) $order['code']);
        $this->redirect('/orders');
    }

    /**
     * TEST ONLY: re-send the Discord purchase notification for an order, to
     * verify the webhook + embed. Owner + feature gated.
     */
    public function testDiscordPurchase(string $id): void
    {
        Auth::requireRoot();
        $this->verifyCsrf();
        if (!Store::testFeatureEnabled('discord_test_purchase')) {
            $this->flash('error', Lang::t('orders.test_mode_off'));
            $this->redirect('/orders');
        }
        $order = OrderModel::find((int) $id);
        if ($order === null) {
            $this->flash('error', Lang::t('store.order_not_found'));
            $this->redirect('/orders');
        }
        \App\Controllers\StoreController::sendPurchaseNotification($order, true);
        $this->flash('success', Lang::t('orders.test_discord_sent'));
        Logger::log('store.test_discord', (string) $order['buyer_steamid'], (string) $order['code']);
        $this->redirect('/orders');
    }

    /**
     * Grant an order's perks (VIP + admin) to each beneficiary. Handles both
     * multi-item (cart) orders and legacy single-item orders. Returns true if
     * at least one grant was applied. Shared by regrant() and markPaidTest().
     */
    private function grantOrder(int $id, array $order): bool
    {
        $granted = false;
        try {
            $items = OrderModel::items($id);
            if ($items) {
                foreach ($items as $it) {
                    if (OrderModel::grantVip($it)) {
                        OrderModel::markItemVipGranted((int) $it['id']);
                        $granted = true;
                    }
                    if (OrderModel::grantAdmin($it)) {
                        OrderModel::markItemAdminGranted((int) $it['id'], OrderModel::adminExpiryFor($it));
                        $granted = true;
                    }
                    if ((int) ($it['credit_amount'] ?? 0) > 0 && empty($it['credits_granted'])) {
                        if (OrderModel::grantCreditsItem($it)) {
                            $granted = true;
                        }
                    }
                }
            } else {
                if (OrderModel::grantVip($order)) {
                    OrderModel::markVipGranted($id);
                    $granted = true;
                }
                if (OrderModel::grantAdmin($order)) {
                    \App\Core\Database::run(
                        'UPDATE ' . OrderModel::TABLE . ' SET admin_granted = 1, admin_expires_at = ? WHERE id = ?',
                        [OrderModel::adminExpiryFor($order), $id]
                    );
                    $granted = true;
                }
            }
            // Store credits (cs2-store): grant on the order itself. Idempotent
            // via credits_granted, so re-running fulfillment never double-credits.
            if ((int) ($order['credit_amount'] ?? 0) > 0 && empty($order['credits_granted'])) {
                if (OrderModel::grantCredits($order)) {
                    $granted = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('[CS2Panel] order grant error: ' . $e->getMessage());
        }
        return $granted;
    }
}
