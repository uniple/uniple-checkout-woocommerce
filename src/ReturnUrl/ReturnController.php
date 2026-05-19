<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\ReturnUrl;

use Uniple\CheckoutWooCommerce\Api\UnipleClient;
use Uniple\CheckoutWooCommerce\Gateway\UnipleGateway;
use Uniple\CheckoutWooCommerce\Webhook\WebhookController;

defined('ABSPATH') || exit;

/**
 * Hosted Checkout からの return URL handler。
 *
 * `woocommerce_api_uniple_return` action 経由で `?wc-api=uniple_return&order_id=<id>` を受領。
 * - mapping が paid なら thank-you redirect (= 正常着地)
 * - pending かつ session_id あれば option C live lookup (= EC-CUBE 4 と同方針)
 *   = uniple Codex r42 の GET sessions contract を流用、 status=completed なら mapping update
 * - 取得失敗 / pending のままなら従来 thank-you 着地 (= webhook 到着待ち UI)
 *
 * cart purge は WC が thank-you 経路で自動実行する想定 (= `wc_get_endpoint_url('order-received')`)。
 */
final class ReturnController
{
    public static function handle(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway return URL; authenticated via WooCommerce order key (hash_equals); a nonce cannot be carried across the external checkout redirect.
        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway return URL; authenticated via WooCommerce order key (hash_equals); a nonce cannot be carried across the external checkout redirect.
        $providedKey = isset($_GET['key']) ? sanitize_text_field((string) wp_unslash($_GET['key'])) : '';
        if ($orderId <= 0 || $providedKey === '') {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $expectedKey = (string) $order->get_order_key();
        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        if (!$order->is_paid()) {
            $sessionId = (string) $order->get_meta('_uniple_session_id', true);
            if ($sessionId !== '') {
                $gateway = self::resolveGateway();
                if ($gateway !== null) {
                    try {
                        $client = new UnipleClient($gateway->clientConfig());
                        $live = $client->getCheckoutSession($sessionId);
                        $item = is_array($live['item'] ?? null) ? $live['item'] : [];
                        $liveStatus = (string) ($item['status'] ?? '');
                        if ($liveStatus === 'completed' && self::completedItemMatchesOrder($item, $order, $client, $orderId, $sessionId)) {
                            $txHash = (string) ($item['txHash'] ?? $item['transactionId'] ?? '');
                            if (!$order->is_paid()) {
                                $order->payment_complete($txHash !== '' ? $txHash : $sessionId);
                                $order->add_order_note(
                                    sprintf(
                                        /* translators: %s: session id */
                                        __('uniple checkout completed via option C live lookup (session=%s).', 'uniple-checkout-for-woocommerce'),
                                        $sessionId
                                    )
                                );
                            }
                        }
                    } catch (\Throwable $e) {
                        wc_get_logger()->warning(
                            '[uniple-checkout] return option C lookup failed: '.$e->getMessage(),
                            ['source' => 'uniple-checkout', 'order_id' => $orderId]
                        );
                    }
                }
            }
        }

        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        // Cross-device thank-you support:
        // uniple hosted checkout は PC QR → スマホ wallet 完走 path で、 uniple SSR は
        // mobile cross-device 起点 (= `?wc=1&handoff=qr`) のときのみ完了画面に留まる
        // (= uniple Codex 査読 r68 確定)。 mobile 単独 cart 着地 = `?wc=1` のみは
        // successUrl 直行 default。
        //
        // WP 側 thank-you で WC 8.4.0+ の verify_known_shoppers filter が customer
        // 紐付き order の known shopper mismatch を保護し「Please log in」 を表示する
        // ため、 ReturnController で order_key hash_equals 検証済の時点で transient に
        // expected key を 30 min 保持し、 後続 `/checkout/order-received/<id>/?key=<key>`
        // request で Plugin::maybeSkipKnownShopperVerify が key 照合 + payment method
        // 限定で per-request に verify を skip する。
        // payment method check と hash_equals 検証は filter 側で行う。
        set_transient(
            'uniple_received_authorized_'.$orderId,
            $expectedKey,
            30 * MINUTE_IN_SECONDS
        );
        wc_get_logger()->info(
            '[uniple-checkout] received authorized',
            ['source' => 'uniple-checkout', 'order_id' => $orderId]
        );

        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    /**
     * @param array<string,mixed> $item
     */
    private static function completedItemMatchesOrder(array $item, \WC_Order $order, UnipleClient $client, int $orderId, string $sessionId): bool
    {
        $clientReferenceId = (string) (
            $item['clientReferenceId']
            ?? $item['client_reference_id']
            ?? $item['merchantOrderId']
            ?? ''
        );
        if ($clientReferenceId === '' || !hash_equals((string) $orderId, $clientReferenceId)) {
            wc_get_logger()->warning(
                '[uniple-checkout] return option C client reference mismatch',
                [
                    'source' => 'uniple-checkout',
                    'order_id' => $orderId,
                    'session_id' => $sessionId,
                    'got' => $clientReferenceId,
                ]
            );

            return false;
        }

        try {
            $actualAmount = $client->toIntegerJpyc($item['amountJpyc'] ?? $item['amount_jpyc'] ?? null);
            $expectedAmount = $client->toIntegerJpyc($order->get_total());
        } catch (\InvalidArgumentException $e) {
            wc_get_logger()->warning(
                '[uniple-checkout] return option C amount missing or invalid: '.$e->getMessage(),
                ['source' => 'uniple-checkout', 'order_id' => $orderId, 'session_id' => $sessionId]
            );

            return false;
        }

        if ($actualAmount !== $expectedAmount) {
            wc_get_logger()->warning(
                '[uniple-checkout] return option C amount mismatch',
                [
                    'source' => 'uniple-checkout',
                    'order_id' => $orderId,
                    'session_id' => $sessionId,
                    'expected' => $expectedAmount,
                    'actual' => $actualAmount,
                ]
            );

            return false;
        }

        return true;
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
