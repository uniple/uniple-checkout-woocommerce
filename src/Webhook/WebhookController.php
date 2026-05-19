<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Webhook;

use Uniple\CheckoutWooCommerce\Api\UnipleClient;
use Uniple\CheckoutWooCommerce\Gateway\UnipleGateway;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * REST /wp-json/uniple/v1/webhook handler。
 *
 * - permission_callback = __return_true (= 公開 endpoint、 認証は HMAC で行う)
 * - raw body + hash_equals で signature 検証
 * - idempotency = event:sessionId key を WC order meta (`_uniple_completed_event_ids`) に永続記録 +
 *   短期 transient で同時受信時の race lock (= 60 秒 SETNX 相当)
 * - paid 確定経路 = `$order->payment_complete($txHash)` (= paid date / lifecycle 整合)
 */
final class WebhookController
{
    public const ROUTE_NAMESPACE = 'uniple/v1';
    public const ROUTE = '/webhook';
    public const META_EVENT_IDS = '_uniple_completed_event_ids';
    public const LOCK_OPTION_PREFIX = 'uniple_webhook_lock_';
    public const LOCK_TTL_SECONDS = 60;

    public static function registerRoutes(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE,
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $rawBody = $request->get_body();
        $sigHeader = (string) $request->get_header('X-Uniple-Signature');

        $gateway = self::resolveGateway();
        if ($gateway === null) {
            return new WP_REST_Response(['error' => 'gateway_not_configured'], 503);
        }

        $client = new UnipleClient($gateway->clientConfig());
        if (!$client->verifySignature($rawBody, $sigHeader)) {
            return new WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return new WP_REST_Response(['error' => 'invalid_payload'], 400);
        }

        $type = (string) ($payload['event'] ?? $payload['type'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $sessionId = (string) ($data['sessionId'] ?? '');
        $clientReferenceId = (string) ($data['clientReferenceId'] ?? '');
        $txHash = (string) ($data['txHash'] ?? $data['transactionId'] ?? '');

        if (
            $type !== 'checkout.session.completed'
            || $sessionId === ''
            || $clientReferenceId === ''
        ) {
            return new WP_REST_Response(['ok' => true, 'ignored' => true], 200);
        }

        $idempotencyKey = $type.':'.$sessionId;

        $orderId = (int) $clientReferenceId;
        $order = wc_get_order($orderId);
        if (!$order) {
            return new WP_REST_Response(['error' => 'order_not_found'], 404);
        }

        $storedSessionId = (string) $order->get_meta('_uniple_session_id', true);
        if ($storedSessionId === '' || !hash_equals($storedSessionId, $sessionId)) {
            wc_get_logger()->warning(
                '[uniple-checkout] webhook session mismatch',
                [
                    'source' => 'uniple-checkout',
                    'order_id' => $orderId,
                    'expected' => $storedSessionId,
                    'got' => $sessionId,
                ]
            );

            return new WP_REST_Response(['error' => 'session_mismatch'], 409);
        }

        try {
            $actualAmount = $client->toIntegerJpyc($data['amountJpyc'] ?? $data['amount_jpyc'] ?? null);
        } catch (\InvalidArgumentException $e) {
            wc_get_logger()->warning(
                '[uniple-checkout] webhook amount missing or invalid: '.$e->getMessage(),
                ['source' => 'uniple-checkout', 'order_id' => $orderId, 'session_id' => $sessionId]
            );

            return new WP_REST_Response(['error' => 'amount_missing_or_invalid'], 400);
        }

        try {
            $expectedAmount = $client->toIntegerJpyc($order->get_total());
        } catch (\InvalidArgumentException $e) {
            wc_get_logger()->error(
                '[uniple-checkout] order amount invalid: '.$e->getMessage(),
                ['source' => 'uniple-checkout', 'order_id' => $orderId, 'session_id' => $sessionId]
            );

            return new WP_REST_Response(['error' => 'order_amount_invalid'], 409);
        }

        if ($actualAmount !== $expectedAmount) {
            wc_get_logger()->warning(
                '[uniple-checkout] webhook amount mismatch',
                [
                    'source' => 'uniple-checkout',
                    'order_id' => $orderId,
                    'session_id' => $sessionId,
                    'expected' => $expectedAmount,
                    'actual' => $actualAmount,
                ]
            );

            return new WP_REST_Response(['error' => 'amount_mismatch'], 409);
        }

        if (!self::acquireLock($orderId)) {
            return new WP_REST_Response(['ok' => true, 'queued' => true], 202);
        }

        try {
            $processed = (array) ($order->get_meta(self::META_EVENT_IDS, true) ?: []);
            if (in_array($idempotencyKey, $processed, true)) {
                return new WP_REST_Response(['ok' => true, 'duplicate' => true], 200);
            }

            if (!$order->is_paid()) {
                $order->payment_complete($txHash !== '' ? $txHash : $sessionId);
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: session id, 2: tx hash */
                        __('uniple checkout completed (session=%1$s, tx=%2$s).', 'uniple-checkout-for-woocommerce'),
                        $sessionId,
                        $txHash !== '' ? $txHash : '-'
                    )
                );
            }

            $processed[] = $idempotencyKey;
            $order->update_meta_data(self::META_EVENT_IDS, array_slice($processed, -50));
            $order->save();
        } finally {
            self::releaseLock($orderId);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Atomic lock via wp_options.
     *
     * `add_option` は INSERT ... 失敗で false を返す原子操作なので、 set_transient より
     * 強い lock になる。 ttl 経過した stale lock は判定後 delete + 再 add で奪取。
     */
    private static function acquireLock(int $orderId): bool
    {
        $key = self::LOCK_OPTION_PREFIX.$orderId;
        $now = time();
        $payload = (string) $now;

        if (add_option($key, $payload, '', false)) {
            return true;
        }

        $existing = (int) get_option($key, 0);
        if ($existing > 0 && ($now - $existing) > self::LOCK_TTL_SECONDS) {
            delete_option($key);
            if (add_option($key, $payload, '', false)) {
                return true;
            }
        }

        return false;
    }

    private static function releaseLock(int $orderId): void
    {
        delete_option(self::LOCK_OPTION_PREFIX.$orderId);
    }

    private static function resolveGateway(): ?UnipleGateway
    {
        if (!function_exists('WC')) {
            return null;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway = $gateways['uniple'] ?? null;

        return $gateway instanceof UnipleGateway ? $gateway : null;
    }
}
