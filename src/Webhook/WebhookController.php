<?php
/*
 * uniple checkout for WooCommerce
 * Copyright (C) 2026 uniple inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Webhook;

use Uniple\CheckoutWooCommerce\Api\UnipleClient;
use Uniple\CheckoutWooCommerce\Gateway\UnipleGateway;
use Uniple\CheckoutWooCommerce\Plugin;
use Uniple\CheckoutWooCommerce\Util\JapaneseAddress;
use Uniple\CheckoutWooCommerce\X402\ProductResolver;
use Uniple\CheckoutWooCommerce\X402\QuoteStore;
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
        $productSku = (string) ($data['productSku'] ?? $data['product_sku'] ?? '');

        if ($type === 'checkout.session.completed' && $productSku !== '' && !self::matchesNormalCheckoutOrder($sessionId, $clientReferenceId)) {
            return self::handleX402Completed($data, $type, $rawBody, $client);
        }

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
     * @param array<string,mixed> $data
     */
    private static function handleX402Completed(array $data, string $type, string $rawBody, UnipleClient $client): WP_REST_Response
    {
        $productSku = self::readPayloadString($data, ['productSku', 'product_sku']);
        $amount = self::normalizeOrderAmount($data['amountJpyc'] ?? $data['amount_jpyc'] ?? null);
        if ($productSku === '' || $amount === null) {
            return new WP_REST_Response(['error' => 'x402_missing_required_field'], 400);
        }

        $product = ProductResolver::findBySku($productSku);
        if (!$product instanceof \WC_Product) {
            return new WP_REST_Response(['error' => 'product_not_found'], 404);
        }

        $sessionId = self::readPayloadString($data, ['sessionId', 'session_id']);
        $merchantOrderId = self::readPayloadString($data, ['merchantOrderId', 'merchant_order_id']);
        $clientReferenceId = self::readPayloadString($data, ['clientReferenceId', 'client_reference_id']);
        $idempotencyRef = $sessionId !== ''
            ? $sessionId
            : ($merchantOrderId !== '' ? $merchantOrderId : ($clientReferenceId !== '' ? $clientReferenceId : hash('sha256', $rawBody)));
        if (strlen($idempotencyRef) > 180) {
            $idempotencyRef = hash('sha256', $idempotencyRef);
        }
        $idempotencyKey = $type.':'.$idempotencyRef;

        $existing = wc_get_orders([
            'limit' => 1,
            'return' => 'ids',
            'meta_key' => '_uniple_x402_idempotency_key',
            'meta_value' => $idempotencyKey,
        ]);
        if (is_array($existing) && count($existing) > 0) {
            return new WP_REST_Response(['ok' => true, 'duplicate' => true, 'orderId' => (int) $existing[0]], 200);
        }

        $quoteId = self::readPayloadString($data, ['quoteId', 'quote_id']);
        $quote = null;
        $quantity = 1;
        $productSubtotal = $amount;
        $shippingFee = '0';
        $discount = '0';
        $total = $amount;
        if ($quoteId !== '') {
            $quote = QuoteStore::find($quoteId);
            if ($quote === null) {
                return new WP_REST_Response(['error' => 'quote_not_found'], 400);
            }
            $quoteError = self::validateQuote($quote, $data, $productSku, $amount, $product);
            if ($quoteError !== null) {
                wc_get_logger()->warning(
                    '[uniple-checkout] x402 quote validation failed',
                    ['source' => 'uniple-checkout', 'product_sku' => $productSku, 'error' => $quoteError]
                );

                return new WP_REST_Response(['error' => $quoteError], 400);
            }
            $quantity = (int) $quote['quantity'];
            $productSubtotal = (string) $quote['productSubtotalJpyc'];
            $shippingFee = (string) $quote['shippingFeeJpyc'];
            $discount = (string) $quote['discountJpyc'];
            $total = (string) $quote['totalJpyc'];
        }

        $lockKey = 'x402_'.hash('sha256', $idempotencyKey);
        if (!self::acquireLockKey($lockKey)) {
            return new WP_REST_Response(['ok' => true, 'queued' => true], 202);
        }

        try {
            $txHash = self::readPayloadString($data, ['txHash', 'tx_hash', 'transactionId', 'transaction_id']);
            $payer = self::readPayloadString($data, ['payer', 'from']);
            $itemName = self::readPayloadString($data, ['itemName', 'item_name']);
            $addressData = $data;
            if (is_array($quote['shipping'] ?? null)) {
                $addressData['shipping'] = $quote['shipping'];
            }
            $address = self::x402Address($addressData, $payer);

            $order = wc_create_order(['created_via' => 'uniple-x402']);
            if (!$order instanceof \WC_Order) {
                return new WP_REST_Response(['error' => 'order_create_failed'], 500);
            }

            $order->set_payment_method(Plugin::PLUGIN_ID);
            $order->set_payment_method_title(__('JPYC決済 (uniple checkout)', 'uniple-checkout-for-woocommerce'));
            $order->set_address($address, 'billing');
            $order->set_address($address, 'shipping');
            $unitPrice = self::unitPriceFromSubtotal($productSubtotal, $quantity);
            if ($unitPrice === null) {
                return new WP_REST_Response(['error' => 'quote_amount_mismatch'], 400);
            }
            $order->add_product($product, $quantity, [
                'name' => $itemName !== '' ? $itemName : $product->get_name(),
                'subtotal' => $productSubtotal,
                'total' => $productSubtotal,
            ]);
            if ((int) $shippingFee > 0) {
                $shippingItem = new \WC_Order_Item_Shipping();
                $shippingItem->set_method_title((string) ($quote['shippingRateLabel'] ?? __('送料', 'uniple-checkout-for-woocommerce')));
                $shippingItem->set_method_id((string) ($quote['shippingRateId'] ?? 'uniple_x402_shipping'));
                $shippingItem->set_total($shippingFee);
                $order->add_item($shippingItem);
            }
            $order->set_currency('JPY');
            $order->set_discount_total((float) $discount);
            $order->set_shipping_total((float) $shippingFee);
            $order->set_total((float) $total);
            $order->update_meta_data('_uniple_x402_idempotency_key', $idempotencyKey);
            $order->update_meta_data('_uniple_x402_product_sku', $productSku);
            $order->update_meta_data('_uniple_x402_quote_id', $quoteId);
            $order->update_meta_data('_uniple_x402_product_subtotal_jpyc', $productSubtotal);
            $order->update_meta_data('_uniple_x402_shipping_fee_jpyc', $shippingFee);
            $order->update_meta_data('_uniple_x402_total_jpyc', $total);
            $order->update_meta_data('_uniple_x402_merchant_order_id', $merchantOrderId);
            $order->update_meta_data('_uniple_x402_client_reference_id', $clientReferenceId);
            $order->update_meta_data('_uniple_x402_tx_hash', $txHash);
            $order->update_meta_data('_uniple_x402_payer', $payer);
            $order->add_order_note(self::x402OrderNote($productSku, $merchantOrderId, $clientReferenceId, $txHash, $payer, $quoteId, $shippingFee, $total));
            if (!$order->is_paid()) {
                $order->payment_complete($txHash !== '' ? $txHash : $idempotencyRef);
            }
            $order->save();
            if ($quote !== null) {
                QuoteStore::markUsed($quoteId);
            }
        } catch (\Throwable $e) {
            wc_get_logger()->error(
                '[uniple-checkout] x402 order creation failed: '.$e->getMessage(),
                ['source' => 'uniple-checkout', 'product_sku' => $productSku]
            );

            return new WP_REST_Response(['error' => 'x402_order_creation_failed'], 500);
        } finally {
            self::releaseLockKey($lockKey);
        }

        return new WP_REST_Response(['ok' => true, 'x402' => true, 'orderId' => $order->get_id()], 200);
    }

    /**
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $data
     */
    private static function validateQuote(array $quote, array $data, string $productSku, string $amount, \WC_Product $product): ?string
    {
        if (!empty($quote['usedAt'])) {
            return 'quote_already_used';
        }
        if (strtotime((string) ($quote['expiresAt'] ?? '')) <= time()) {
            return 'quote_expired';
        }
        if ((string) ($quote['productSku'] ?? '') !== $productSku || (int) ($quote['productId'] ?? 0) !== (int) $product->get_id()) {
            return 'quote_product_mismatch';
        }
        if ((string) ($quote['totalJpyc'] ?? '') !== $amount) {
            return 'quote_amount_mismatch';
        }

        $quantity = self::readPayloadString($data, ['quantity', 'qty']);
        if ($quantity !== '' && (!ctype_digit($quantity) || (int) $quantity !== (int) $quote['quantity'])) {
            return 'quote_quantity_mismatch';
        }

        $subtotal = self::readPayloadAmount($data, ['productSubtotalJpyc', 'product_subtotal_jpyc']);
        if ($subtotal !== null && $subtotal !== (string) $quote['productSubtotalJpyc']) {
            return 'quote_product_subtotal_mismatch';
        }
        $shippingFee = self::readPayloadAmount($data, ['shippingFeeJpyc', 'shipping_fee_jpyc']);
        if ($shippingFee !== null && $shippingFee !== (string) $quote['shippingFeeJpyc']) {
            return 'quote_shipping_fee_mismatch';
        }
        $total = self::readPayloadAmount($data, ['totalJpyc', 'total_jpyc']);
        if ($total !== null && $total !== (string) $quote['totalJpyc']) {
            return 'quote_total_mismatch';
        }

        return null;
    }

    /**
     * Atomic lock via wp_options.
     *
     * `add_option` は INSERT ... 失敗で false を返す原子操作なので、 set_transient より
     * 強い lock になる。 ttl 経過した stale lock は判定後 delete + 再 add で奪取。
     */
    private static function acquireLock(int $orderId): bool
    {
        return self::acquireLockKey((string) $orderId);
    }

    private static function acquireLockKey(string $suffix): bool
    {
        $key = self::lockOptionKey($suffix);
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
        self::releaseLockKey((string) $orderId);
    }

    private static function releaseLockKey(string $suffix): void
    {
        delete_option(self::lockOptionKey($suffix));
    }

    private static function lockOptionKey(string $suffix): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $suffix) ?? '';
        if (strlen($safe) > 80) {
            $safe = hash('sha256', $safe);
        }

        return self::LOCK_OPTION_PREFIX.$safe;
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

    private static function matchesNormalCheckoutOrder(string $sessionId, string $clientReferenceId): bool
    {
        if ($sessionId === '' || $clientReferenceId === '' || !ctype_digit($clientReferenceId)) {
            return false;
        }
        $order = wc_get_order((int) $clientReferenceId);
        if (!$order instanceof \WC_Order) {
            return false;
        }
        $storedSessionId = (string) $order->get_meta('_uniple_session_id', true);

        return $storedSessionId !== '' && hash_equals($storedSessionId, $sessionId);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string>   $keys
     */
    private static function readPayloadString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return trim((string) $data[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,string>
     */
    private static function x402Address(array $data, string $payer): array
    {
        $shipping = self::x402ShippingPayload($data);
        [$fallbackLastName, $fallbackFirstName] = self::x402BuyerName($data, $payer);

        $firstName = self::readPayloadString($shipping, ['firstName', 'first_name', 'givenName', 'given_name', 'name02']);
        $lastName = self::readPayloadString($shipping, ['lastName', 'last_name', 'familyName', 'family_name', 'name01']);
        $fullName = self::readPayloadString($shipping, ['name', 'fullName', 'full_name', 'recipientName', 'recipient_name', 'shippingName', 'shipping_name']);
        if (($firstName === '' || $lastName === '') && $fullName !== '') {
            $parts = preg_split('/\s+/u', $fullName, 2, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($lastName === '') {
                $lastName = (string) ($parts[0] ?? '');
            }
            if ($firstName === '') {
                $firstName = (string) ($parts[1] ?? '');
            }
        }
        if ($lastName === '') {
            $lastName = $fallbackLastName;
        }
        if ($firstName === '') {
            $firstName = $fallbackFirstName;
        }

        $city = self::readPayloadString($shipping, ['city', 'municipality', 'ward']);
        $address1 = self::readPayloadString($shipping, ['addr01', 'address1', 'address_1', 'addressLine1', 'address_line1', 'line1', 'streetAddress', 'street_address']);
        $address2 = self::readPayloadString($shipping, ['addr02', 'address2', 'address_2', 'addressLine2', 'address_line2', 'line2', 'building', 'apartment', 'room']);
        $phone = self::readPayloadString($shipping, ['phoneNumber', 'phone_number', 'phone', 'tel', 'telephone']);
        $postcode = self::readPayloadString($shipping, ['postalCode', 'postal_code', 'postCode', 'post_code', 'zipCode', 'zip_code', 'zipcode', 'zip']);
        $state = self::normalizePrefName(self::readPayloadString($shipping, ['state', 'pref', 'prefName', 'pref_name', 'prefecture', 'province', 'region']));
        $address = JapaneseAddress::normalize($state, $city, $address1, $address2);

        return [
            'first_name' => mb_substr($firstName, 0, 255),
            'last_name' => mb_substr($lastName, 0, 255),
            'email' => mb_substr(self::readPayloadString($shipping, ['email', 'mail']) ?: 'x402-agent@uniple.local', 0, 255),
            'phone' => mb_substr($phone !== '' ? $phone : '0000000000', 0, 32),
            'address_1' => mb_substr($address['address1'] !== '' ? $address['address1'] : 'x402', 0, 255),
            'address_2' => mb_substr($address['address2'], 0, 255),
            'postcode' => mb_substr($postcode !== '' ? $postcode : '0000000', 0, 32),
            'city' => mb_substr($address['city'] !== '' ? $address['city'] : 'x402', 0, 255),
            'state' => mb_substr($state !== '' ? $state : '東京都', 0, 255),
            'country' => 'JP',
        ];
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private static function x402ShippingPayload(array $data): array
    {
        foreach (['shipping', 'shippingAddress', 'shipping_address', 'delivery', 'recipient'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array{0:string,1:string}
     */
    private static function x402BuyerName(array $data, string $payer): array
    {
        $raw = self::readPayloadString($data, ['buyerName', 'buyer_name', 'name']);
        if ($raw === '' && $payer !== '') {
            $raw = 'x402 '.substr($payer, 0, 12);
        }
        if ($raw === '') {
            return ['x402', 'Buyer'];
        }

        $parts = preg_split('/\s+/u', $raw, 2, PREG_SPLIT_NO_EMPTY) ?: [];
        return [
            mb_substr((string) ($parts[0] ?? 'x402'), 0, 255),
            mb_substr((string) ($parts[1] ?? 'Buyer'), 0, 255),
        ];
    }

    private static function normalizePrefName(string $prefName): string
    {
        $prefName = trim($prefName);
        $map = [
            'tokyo' => '東京都',
            'tokyo-to' => '東京都',
            'osaka' => '大阪府',
            'osaka-fu' => '大阪府',
            'kyoto' => '京都府',
            'kyoto-fu' => '京都府',
            'hokkaido' => '北海道',
            'kanagawa' => '神奈川県',
            'saitama' => '埼玉県',
            'chiba' => '千葉県',
            'aichi' => '愛知県',
            'fukuoka' => '福岡県',
        ];
        $key = strtolower(str_replace([' ', '_'], '-', $prefName));

        return $map[$key] ?? $prefName;
    }

    private static function x402OrderNote(
        string $productSku,
        string $merchantOrderId,
        string $clientReferenceId,
        string $txHash,
        string $payer,
        string $quoteId = '',
        string $shippingFeeJpyc = '',
        string $totalJpyc = ''
    ): string {
        $lines = [
            'uniple x402 purchase',
            'productSku: '.$productSku,
        ];
        if ($quoteId !== '') {
            $lines[] = 'quoteId: '.$quoteId;
        }
        if ($merchantOrderId !== '') {
            $lines[] = 'merchantOrderId: '.$merchantOrderId;
        }
        if ($clientReferenceId !== '') {
            $lines[] = 'clientReferenceId: '.$clientReferenceId;
        }
        if ($txHash !== '') {
            $lines[] = 'txHash: '.$txHash;
        }
        if ($payer !== '') {
            $lines[] = 'payer: '.$payer;
        }
        if ($shippingFeeJpyc !== '') {
            $lines[] = 'shippingFeeJpyc: '.$shippingFeeJpyc;
        }
        if ($totalJpyc !== '') {
            $lines[] = 'totalJpyc: '.$totalJpyc;
        }

        return mb_substr(implode("\n", $lines), 0, 4000);
    }

    private static function normalizeOrderAmount(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false || !is_scalar($value)) {
            return null;
        }
        $s = trim((string) $value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $s, $m)) {
            return null;
        }
        $integer = ltrim($m[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = isset($m[2]) ? rtrim($m[2], '0') : '';
        if ($integer === '0' && $fraction === '') {
            return null;
        }

        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string>   $keys
     */
    private static function readPayloadAmount(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $amount = self::normalizeQuoteAmount($data[$key]);

                return $amount === null ? '__invalid__' : $amount;
            }
        }

        return null;
    }

    private static function normalizeQuoteAmount(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false || !is_scalar($value)) {
            return null;
        }
        $s = trim((string) $value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $s, $m)) {
            return null;
        }
        $integer = ltrim($m[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = isset($m[2]) ? rtrim($m[2], '0') : '';

        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    private static function unitPriceFromSubtotal(string $productSubtotal, int $quantity): ?string
    {
        if ($quantity < 1) {
            return null;
        }
        if ($quantity === 1) {
            return $productSubtotal;
        }
        if (!ctype_digit($productSubtotal)) {
            return null;
        }
        $subtotal = (int) $productSubtotal;
        if ($subtotal % $quantity !== 0) {
            return null;
        }

        return (string) intdiv($subtotal, $quantity);
    }
}
