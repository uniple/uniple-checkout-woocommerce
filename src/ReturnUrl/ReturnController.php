<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\ReturnUrl;

use Uniple\CheckoutWooCommerce\Api\UnipleClient;
use Uniple\CheckoutWooCommerce\Gateway\UnipleGateway;
use Uniple\CheckoutWooCommerce\Webhook\WebhookController;

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
        $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        $providedKey = isset($_GET['key']) ? sanitize_text_field((string) wp_unslash((string) $_GET['key'])) : '';
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
                        if ($liveStatus === 'completed') {
                            $txHash = (string) ($item['txHash'] ?? $item['transactionId'] ?? '');
                            if (!$order->is_paid()) {
                                $order->payment_complete($txHash !== '' ? $txHash : $sessionId);
                                $order->add_order_note(
                                    sprintf(
                                        /* translators: %s: session id */
                                        __('uniple checkout completed via option C live lookup (session=%s).', 'uniple-checkout-woocommerce'),
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
        // uniple hosted checkout は QR で別 device から決済完了する flow が一般的
        // (= PC で QR 表示 → スマホ wallet で送金 → 着地 URL がスマホ device)。
        // mark this order key as authorized in transient (= 30 min TTL) なので、
        // 続く /checkout/order-received/<id>/?key=<key> request で filter が
        // この order に対してのみ verify を skip する。
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
