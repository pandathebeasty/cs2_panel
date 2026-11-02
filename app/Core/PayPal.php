<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal PayPal REST client (Orders API v2 + Payments refund + Webhook verify).
 *
 * Credentials and mode come from Settings (store_paypal_client_id / _secret /
 * store_paypal_mode). All amounts are decimal strings (e.g. "10.72"), the
 * format PayPal expects. Nothing here throws on a network/API error; each call
 * returns a structured result so callers decide what to do.
 *
 * Docs summarised for compliance; see developer.paypal.com for the full API.
 */
final class PayPal
{
    private const SANDBOX = 'https://api-m.sandbox.paypal.com';
    private const LIVE    = 'https://api-m.paypal.com';

    /** Last authentication/API error reason (for diagnostics in the UI/logs). */
    private static string $lastError = '';

    /** The reason the last PayPal call failed (empty if none). */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function configured(): bool
    {
        return Store::paypalClientId() !== '' && Store::paypalSecret() !== '';
    }

    private static function baseUrl(): string
    {
        return Store::paypalMode() === 'live' ? self::LIVE : self::SANDBOX;
    }

    private static function clientId(): string
    {
        return Store::paypalClientId();
    }

    private static function secret(): string
    {
        return Store::paypalSecret();
    }

    /**
     * Fetch an OAuth2 access token (client_credentials). Cached in-process for
     * the request. Returns '' on failure.
     */
    private static function accessToken(): string
    {
        static $token = null;
        if ($token !== null) {
            return $token;
        }
        $token = '';

        if (!self::configured()) {
            return $token;
        }

        $res = self::http(
            'POST',
            '/v1/oauth2/token',
            'grant_type=client_credentials',
            ['Content-Type: application/x-www-form-urlencoded'],
            self::clientId() . ':' . self::secret()
        );
        if ($res['ok'] && isset($res['json']['access_token'])) {
            $token = (string) $res['json']['access_token'];
        } else {
            // Log the exact PayPal reason (e.g. "invalid_client") so config
            // problems are diagnosable instead of a generic failure.
            $reason = $res['json']['error_description']
                ?? ($res['json']['error'] ?? ($res['error'] ?: ('HTTP ' . $res['status'])));
            self::$lastError = (string) $reason;
            error_log('[CS2Panel] PayPal token error (' . Store::paypalMode() . ', HTTP ' . $res['status'] . '): ' . (string) $reason);
        }
        return $token;
    }

    /**
     * Create an order for a single item.
     *
     * @param string $amount   decimal string, e.g. "10.72"
     * @param string $currency ISO code, e.g. "EUR"
     * @param string $reference an internal reference (our order code)
     * @param string $description short line shown to the buyer
     * @param string $returnUrl  where PayPal sends the buyer after approval
     * @param string $cancelUrl  where PayPal sends the buyer on cancel
     * @return array{ok:bool, id:string, approveUrl:string, error:string}
     */
    public static function createOrder(
        string $amount,
        string $currency,
        string $reference,
        string $description,
        string $returnUrl,
        string $cancelUrl
    ): array {
        $token = self::accessToken();
        if ($token === '') {
            return ['ok' => false, 'id' => '', 'approveUrl' => '', 'error' => 'Could not authenticate with PayPal.'];
        }

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => mb_substr($reference, 0, 127),
                'description'  => mb_substr($description, 0, 127),
                'amount' => [
                    'currency_code' => $currency,
                    'value'         => $amount,
                ],
            ]],
            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',
                'user_action'         => 'PAY_NOW',
                'return_url'          => $returnUrl,
                'cancel_url'          => $cancelUrl,
            ],
        ];

        $res = self::http('POST', '/v2/checkout/orders', json_encode($payload), [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);

        if (!$res['ok'] || empty($res['json']['id'])) {
            return ['ok' => false, 'id' => '', 'approveUrl' => '', 'error' => self::errorFrom($res)];
        }

        $approve = '';
        foreach (($res['json']['links'] ?? []) as $link) {
            if (($link['rel'] ?? '') === 'approve' || ($link['rel'] ?? '') === 'payer-action') {
                $approve = (string) $link['href'];
                break;
            }
        }
        return ['ok' => true, 'id' => (string) $res['json']['id'], 'approveUrl' => $approve, 'error' => ''];
    }

    /**
     * Capture an approved order.
     *
     * @return array{ok:bool, status:string, captureId:string, amount:string, currency:string, error:string}
     */
    public static function captureOrder(string $orderId): array
    {
        $token = self::accessToken();
        if ($token === '') {
            return ['ok' => false, 'status' => '', 'captureId' => '', 'amount' => '', 'currency' => '', 'error' => 'auth failed'];
        }

        $res = self::http('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', '{}', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);

        if (!$res['ok']) {
            return ['ok' => false, 'status' => '', 'captureId' => '', 'amount' => '', 'currency' => '', 'error' => self::errorFrom($res)];
        }

        $status = (string) ($res['json']['status'] ?? '');
        $cap = $res['json']['purchase_units'][0]['payments']['captures'][0] ?? null;
        $captureId = (string) ($cap['id'] ?? '');
        $amount    = (string) ($cap['amount']['value'] ?? '');
        $currency  = (string) ($cap['amount']['currency_code'] ?? '');

        return [
            'ok'        => $status === 'COMPLETED',
            'status'    => $status,
            'captureId' => $captureId,
            'amount'    => $amount,
            'currency'  => $currency,
            'error'     => $status === 'COMPLETED' ? '' : ('status=' . $status),
        ];
    }

    /**
     * Look up an order's current status (used by the webhook as a source of
     * truth, so a spoofed webhook body can't fake a payment).
     *
     * @return array{ok:bool, status:string, error:string}
     */
    public static function getOrder(string $orderId): array
    {
        $token = self::accessToken();
        if ($token === '') {
            return ['ok' => false, 'status' => '', 'error' => 'auth failed'];
        }
        $res = self::http('GET', '/v2/checkout/orders/' . rawurlencode($orderId), null, [
            'Authorization: Bearer ' . $token,
        ]);
        if (!$res['ok']) {
            return ['ok' => false, 'status' => '', 'error' => self::errorFrom($res)];
        }
        return ['ok' => true, 'status' => (string) ($res['json']['status'] ?? ''), 'error' => ''];
    }

    /**
     * Refund a capture (full refund when $amount is empty, else a partial).
     *
     * @return array{ok:bool, refundId:string, status:string, error:string}
     */
    public static function refund(string $captureId, string $amount = '', string $currency = ''): array
    {
        $token = self::accessToken();
        if ($token === '') {
            return ['ok' => false, 'refundId' => '', 'status' => '', 'error' => 'auth failed'];
        }

        $body = '{}';
        if ($amount !== '' && $currency !== '') {
            $body = json_encode(['amount' => ['value' => $amount, 'currency_code' => $currency]]);
        }

        $res = self::http('POST', '/v2/payments/captures/' . rawurlencode($captureId) . '/refund', $body, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);

        if (!$res['ok']) {
            return ['ok' => false, 'refundId' => '', 'status' => '', 'error' => self::errorFrom($res)];
        }
        $status = (string) ($res['json']['status'] ?? '');
        return [
            'ok'       => in_array($status, ['COMPLETED', 'PENDING'], true),
            'refundId' => (string) ($res['json']['id'] ?? ''),
            'status'   => $status,
            'error'    => '',
        ];
    }

    /**
     * Verify a webhook signature with PayPal (verify-webhook-signature API).
     * Requires the webhook id configured in Settings. Returns true only when
     * PayPal reports verification_status = SUCCESS.
     *
     * @param array<string,string> $headers request headers (case-insensitive keys handled by caller)
     * @param string $rawBody the exact raw request body
     */
    public static function verifyWebhook(array $headers, string $rawBody): bool
    {
        $webhookId = Store::paypalWebhookId();
        if ($webhookId === '') {
            // No webhook id configured — cannot verify. Caller should fall back
            // to fetching the order status directly instead of trusting this.
            return false;
        }
        $token = self::accessToken();
        if ($token === '') {
            return false;
        }

        // Normalize header lookup to lower-case keys.
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string) $k)] = $v;
        }
        $get = static fn(string $name): string => (string) ($h[$name] ?? '');

        $bodyJson = json_decode($rawBody, true);
        if (!is_array($bodyJson)) {
            return false;
        }

        $payload = [
            'auth_algo'         => $get('paypal-auth-algo'),
            'cert_url'          => $get('paypal-cert-url'),
            'transmission_id'   => $get('paypal-transmission-id'),
            'transmission_sig'  => $get('paypal-transmission-sig'),
            'transmission_time' => $get('paypal-transmission-time'),
            'webhook_id'        => $webhookId,
            'webhook_event'     => $bodyJson,
        ];

        $res = self::http('POST', '/v1/notifications/verify-webhook-signature', json_encode($payload), [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);

        return $res['ok'] && (($res['json']['verification_status'] ?? '') === 'SUCCESS');
    }

    /** Pull a human-readable error out of a PayPal error response. */
    private static function errorFrom(array $res): string
    {
        if ($res['error'] !== '') {
            return $res['error'];
        }
        $j = $res['json'] ?? [];
        if (isset($j['details'][0]['description'])) {
            return (string) $j['details'][0]['description'];
        }
        if (isset($j['message'])) {
            return (string) $j['message'];
        }
        return 'PayPal error (HTTP ' . $res['status'] . ')';
    }

    /**
     * Low-level HTTP call to the PayPal API.
     *
     * @param string|null $body       request body (null for GET)
     * @param string[]    $headers    request headers
     * @param string      $basicAuth  "client:secret" for the token call (else '')
     * @return array{ok:bool, status:int, json:array, error:string}
     */
    private static function http(string $method, string $path, ?string $body, array $headers, string $basicAuth = ''): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'json' => [], 'error' => 'cURL is not installed on the server.'];
        }

        $ch = curl_init(self::baseUrl() . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($basicAuth !== '') {
            $opts[CURLOPT_USERPWD] = $basicAuth;
        }
        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if ($ca && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'status' => $status, 'json' => [], 'error' => $err ?: 'request failed'];
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            $json = [];
        }
        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'json'   => $json,
            'error'  => '',
        ];
    }
}
