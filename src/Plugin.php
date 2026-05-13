<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce;

use Uniple\CheckoutWooCommerce\Gateway\UnipleBlockSupport;
use Uniple\CheckoutWooCommerce\Gateway\UnipleGateway;
use Uniple\CheckoutWooCommerce\ReturnUrl\ReturnController;
use Uniple\CheckoutWooCommerce\Webhook\WebhookController;

final class Plugin
{
    public const VERSION = '0.1.0';
    public const PLUGIN_ID = 'uniple';

    public static function boot(): void
    {
        if (!class_exists(\WooCommerce::class)) {
            return;
        }

        add_filter('woocommerce_payment_gateways', [self::class, 'registerGateway']);
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            [self::class, 'registerBlockSupport']
        );
        add_action('rest_api_init', [WebhookController::class, 'registerRoutes']);
        add_action('woocommerce_api_uniple_return', [ReturnController::class, 'handle']);

        // Cross-device thank-you support (= cf. ReturnController::handle())。
        // QR 決済で別 device から /checkout/order-received/<id>/?key=<key> に
        // 着地した場合、 WC 8.4.0+ で導入された
        // `woocommerce_order_received_verify_known_shoppers` filter (default=true) が
        // **customer_id ありの order の known shopper mismatch** を保護し
        // 「Please log in」 を表示する (= 真の guest order は別経路で email verification
        // 等が走るが、 customer 紐付き order でログインしていない / 別 user session の
        // device から着地した場合がここに該当)。
        //
        // ReturnController 経由で order_key hash_equals 検証済 transient が
        // 該当 order に立っていれば、 この request に限り verify を false に倒し、
        // EC-CUBE 4 plugin と同等の cross-device thank-you 表示を実現する。
        //
        // filter signature は WC 8.4.0+ で `apply_filters(..., true)` の単一引数、
        // order_id は渡らないので get_query_var / $_GET から自前で取得する。
        add_filter(
            'woocommerce_order_received_verify_known_shoppers',
            [self::class, 'maybeSkipKnownShopperVerify']
        );
    }

    /**
     * @param bool $verify
     */
    public static function maybeSkipKnownShopperVerify($verify)
    {
        if (!$verify) {
            return $verify;
        }

        $orderId = self::resolveReceivedOrderId();
        if ($orderId <= 0) {
            return $verify;
        }

        $authorizedKey = get_transient('uniple_received_authorized_'.$orderId);
        if (!is_string($authorizedKey) || $authorizedKey === '') {
            return $verify;
        }

        $providedKey = isset($_GET['key']) ? (string) wp_unslash((string) $_GET['key']) : '';
        if ($providedKey === '' || !hash_equals($authorizedKey, $providedKey)) {
            return $verify;
        }

        // payment method 限定 = uniple 決済以外の order に影響しない安全策
        $order = wc_get_order($orderId);
        if (!$order || $order->get_payment_method() !== self::PLUGIN_ID) {
            return $verify;
        }

        // single-use: 認可済を確認したら即削除して再利用を防止
        // (= codex r68 §3.2 任意改善、 transient TTL 30 min と二重 fail-safe)
        delete_transient('uniple_received_authorized_'.$orderId);

        // この request 限定で verify skip (= filter は per-request)
        return false;
    }

    /**
     * URL or query_var から `order-received` の order_id を取得。
     * WC は pretty permalink (= /checkout/order-received/<id>/?key=<key>) と
     * legacy query (= ?order-received=<id>) の両形式を持つ。
     */
    private static function resolveReceivedOrderId(): int
    {
        $fromQueryVar = (int) get_query_var('order-received', 0);
        if ($fromQueryVar > 0) {
            return $fromQueryVar;
        }
        if (isset($_GET['order-received'])) {
            return (int) $_GET['order-received'];
        }

        return 0;
    }

    /**
     * @param array<int, string> $gateways
     *
     * @return array<int, string>
     */
    public static function registerGateway(array $gateways): array
    {
        $gateways[] = UnipleGateway::class;

        return $gateways;
    }

    public static function registerBlockSupport(
        \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry
    ): void {
        $registry->register(new UnipleBlockSupport());
    }
}
