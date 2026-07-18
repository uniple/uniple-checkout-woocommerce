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
use Uniple\CheckoutWooCommerce\X402\PaidQuoteRedemption;
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

        $orderLockToken = self::acquireLock($orderId);
        if ($orderLockToken === null) {
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
            self::releaseLock($orderId, $orderLockToken);
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

        $sessionId = self::readPayloadString($data, ['sessionId', 'session_id']);
        $merchantOrderId = self::readPayloadString($data, ['merchantOrderId', 'merchant_order_id']);
        $clientReferenceId = self::readPayloadString($data, ['clientReferenceId', 'client_reference_id']);
        $txHash = self::readPayloadString($data, ['txHash', 'tx_hash', 'transactionId', 'transaction_id']);
        if (!preg_match('/^0x[0-9a-fA-F]{64}$/', $txHash)) {
            return new WP_REST_Response(['error' => 'tx_hash_missing_or_invalid'], 400);
        }
        $idempotencyRef = $sessionId !== ''
            ? $sessionId
            : ($merchantOrderId !== '' ? $merchantOrderId : ($clientReferenceId !== '' ? $clientReferenceId : hash('sha256', $rawBody)));
        if (strlen($idempotencyRef) > 180) {
            $idempotencyRef = hash('sha256', $idempotencyRef);
        }
        $idempotencyKey = $type.':'.$idempotencyRef;
        $quoteId = self::readPayloadString($data, ['quoteId', 'quote_id']);

        $idempotencyLockKey = 'x402_idempotency_'.hash('sha256', $idempotencyKey);

        if ($quoteId === '') {
            $idempotencyLockToken = self::acquireLockKey($idempotencyLockKey);
            if ($idempotencyLockToken === null) {
                return self::retryableX402Response('x402_lock_busy');
            }
            try {
                $payloadError = self::validateUnquotedPayload($data, $amount);
                if ($payloadError !== null) {
                    return new WP_REST_Response(['error' => $payloadError], 400);
                }
                $owner = [
                    'idempotencyKey' => $idempotencyKey,
                    'txHash' => $txHash,
                    'productSku' => $productSku,
                    'amount' => $amount,
                    'payloadHash' => self::unquotedPayloadHash($data, $productSku, $amount),
                ];
                $claim = QuoteStore::findUnquotedClaim($idempotencyKey);
                if ($claim !== null && !QuoteStore::unquotedClaimMatches($claim, $owner)) {
                    return new WP_REST_Response(['error' => 'x402_idempotency_conflict'], 409);
                }
                $existingOrder = self::findExistingX402Order($idempotencyKey);
                $existingResponse = self::existingX402OrderResponse(
                    $idempotencyKey,
                    $txHash,
                    $productSku,
                    $amount,
                    $data
                );
                if ($existingResponse instanceof WP_REST_Response && $existingResponse->get_status() === 409) {
                    return $existingResponse;
                }

                if ($claim === null) {
                    $claimResult = QuoteStore::claimUnquoted($owner);
                    if ($claimResult['status'] === 'conflict' || $claimResult['claim'] === null) {
                        return new WP_REST_Response(['error' => 'x402_idempotency_conflict'], 409);
                    }
                    $claim = $claimResult['claim'];
                }

                $claimOrderId = (int) ($claim['orderId'] ?? 0);
                if (!$existingOrder instanceof \WC_Order && $claimOrderId > 0) {
                    $existingOrder = wc_get_order($claimOrderId);
                    if (!$existingOrder instanceof \WC_Order) {
                        return self::retryableX402Response('x402_claim_order_missing', [
                            'manualRecoveryRequired' => true,
                            'orderId' => $claimOrderId,
                        ]);
                    }
                }
                if ($existingOrder instanceof \WC_Order) {
                    if (!self::unquotedOrderMatchesClaim($existingOrder, $claim, $productSku, $amount, $txHash)) {
                        return new WP_REST_Response(['error' => 'x402_duplicate_payload_mismatch'], 409);
                    }
                    $storedClaimToken = (string) $existingOrder->get_meta('_uniple_x402_claim_token', true);
                    if ($storedClaimToken === '') {
                        $existingOrder->update_meta_data('_uniple_x402_claim_token', (string) $claim['claimToken']);
                        $existingOrder->update_meta_data('_uniple_x402_payload_hash', (string) $claim['payloadHash']);
                        $existingOrder->save();
                    } elseif (!hash_equals($storedClaimToken, (string) $claim['claimToken'])) {
                        return new WP_REST_Response(['error' => 'x402_claim_order_conflict'], 409);
                    }
                    if (!QuoteStore::attachUnquotedOrder(
                        $idempotencyKey,
                        (string) $claim['claimToken'],
                        $existingOrder->get_id()
                    )) {
                        return self::retryableX402Response('x402_claim_attach_pending');
                    }

                    return self::fulfillUnquotedX402Order(
                        $existingOrder,
                        $claim,
                        null,
                        $data,
                        $productSku,
                        $amount,
                        $idempotencyKey,
                        $idempotencyRef,
                        $merchantOrderId,
                        $clientReferenceId,
                        $txHash
                    );
                }

                $product = ProductResolver::findBySku($productSku);
                if (!$product instanceof \WC_Product) {
                    return self::retryableX402Response('product_unavailable_after_settlement', [
                        'manualRecoveryRequired' => true,
                    ]);
                }

                return self::fulfillUnquotedX402Order(
                    null,
                    $claim,
                    $product,
                    $data,
                    $productSku,
                    $amount,
                    $idempotencyKey,
                    $idempotencyRef,
                    $merchantOrderId,
                    $clientReferenceId,
                    $txHash
                );
            } finally {
                self::releaseLockKey($idempotencyLockKey, $idempotencyLockToken);
            }
        }

        $idempotencyLockToken = self::acquireLockKey($idempotencyLockKey);
        if ($idempotencyLockToken === null) {
            return self::retryableX402Response('x402_lock_busy');
        }
        $quoteLockKey = 'x402_quote_'.hash('sha256', $quoteId);
        $quoteLockToken = self::acquireLockKey($quoteLockKey);
        if ($quoteLockToken === null) {
            self::releaseLockKey($idempotencyLockKey, $idempotencyLockToken);

            return self::retryableX402Response('x402_quote_lock_busy');
        }

        try {
            $quote = QuoteStore::find($quoteId);
            if ($quote === null) {
                return new WP_REST_Response(['error' => 'quote_not_found'], 400);
            }

            $owner = [
                'idempotencyKey' => $idempotencyKey,
                'sessionId' => $sessionId,
                'txHash' => $txHash,
                'payloadHash' => self::claimPayloadHash($quote, $data, $productSku, $amount),
            ];
            $claim = QuoteStore::findClaim($quoteId);
            $existingOrder = self::findExistingX402Order($idempotencyKey);
            if ($existingOrder instanceof \WC_Order) {
                $storedTxHash = (string) $existingOrder->get_meta('_uniple_x402_tx_hash', true);
                if ($storedTxHash !== '' && !PaidQuoteRedemption::transactionMatches($storedTxHash, $txHash)) {
                    return new WP_REST_Response(['error' => 'x402_idempotency_conflict'], 409);
                }
                if (!self::quotedOrderMatchesPayload($existingOrder, $quote, $data, $productSku, $amount)) {
                    return new WP_REST_Response(['error' => 'x402_duplicate_payload_mismatch'], 409);
                }
            }
            if ($claim !== null && !QuoteStore::claimMatches($claim, $owner)) {
                return new WP_REST_Response(['error' => 'quote_claim_conflict'], 409);
            }

            // A legacy paid order plus used quote is already safe even if it
            // predates durable claims. Woo CRUD works for both HPOS and posts.
            if ($claim === null && !empty($quote['usedAt'])) {
                if (
                    $existingOrder instanceof \WC_Order
                    && $existingOrder->is_paid()
                    && self::quotedOrderMatchesPayload($existingOrder, $quote, $data, $productSku, $amount)
                ) {
                    return self::x402OrderAcknowledgement($existingOrder, [
                        'duplicate' => true,
                    ]);
                }

                return new WP_REST_Response([
                    'error' => $existingOrder instanceof \WC_Order
                        ? 'x402_duplicate_payload_mismatch'
                        : 'quote_already_used',
                ], 409);
            }

            $claimOrderId = (int) ($claim['orderId'] ?? 0);
            if (!$existingOrder instanceof \WC_Order && $claimOrderId > 0) {
                $claimedOrder = wc_get_order($claimOrderId);
                if (!$claimedOrder instanceof \WC_Order) {
                    return self::retryableX402Response('quote_claim_order_missing', [
                        'recoveryPending' => true,
                        'manualRecoveryRequired' => true,
                        'orderId' => $claimOrderId,
                    ]);
                }
                $existingOrder = $claimedOrder;
                if (!self::quotedOrderMatchesPayload($existingOrder, $quote, $data, $productSku, $amount)) {
                    return new WP_REST_Response(['error' => 'x402_duplicate_payload_mismatch'], 409);
                }
            }

            // Adopt an exact pre-claim order before catalog resolution. This
            // covers a crash after order persistence in older plugin builds
            // and lets the retry durably finish the quote association.
            if ($claim === null && $existingOrder instanceof \WC_Order) {
                $claimResult = QuoteStore::claimUnused($quoteId, $owner);
                if ($claimResult['status'] === 'conflict') {
                    return new WP_REST_Response(['error' => 'quote_claim_conflict'], 409);
                }
                if ($claimResult['status'] === 'used') {
                    return new WP_REST_Response(['error' => 'quote_already_used'], 409);
                }
                if ($claimResult['status'] === 'missing' || $claimResult['claim'] === null) {
                    return self::retryableX402Response('quote_claim_failed');
                }
                $claim = $claimResult['claim'];
            }

            // A committed skeleton contains the full quoted line/shipping
            // state. Recover it before resolving the live product so a later
            // deletion/unpublish cannot strand an already-paid redemption.
            if ($claim !== null && $existingOrder instanceof \WC_Order) {
                if ($claimOrderId > 0 && $claimOrderId !== $existingOrder->get_id()) {
                    return new WP_REST_Response(['error' => 'quote_claim_order_conflict'], 409);
                }
                $storedClaimToken = (string) $existingOrder->get_meta('_uniple_x402_claim_token', true);
                if ($storedClaimToken === '') {
                    $existingOrder->update_meta_data('_uniple_x402_claim_token', (string) $claim['claimToken']);
                    $existingOrder->save();
                } elseif (!hash_equals($storedClaimToken, (string) $claim['claimToken'])) {
                    return new WP_REST_Response(['error' => 'quote_claim_order_conflict'], 409);
                }
                if ($claimOrderId === 0 && !QuoteStore::attachOrder(
                    $quoteId,
                    (string) $claim['claimToken'],
                    $existingOrder->get_id()
                )) {
                    return self::retryableX402Response('quote_claim_attach_failed');
                }
                $claim['orderId'] = $existingOrder->get_id();

                return self::fulfillClaimedX402Order(
                    $existingOrder,
                    $claim,
                    $quote,
                    null,
                    self::x402Address(
                        is_array($quote['shipping'] ?? null)
                            ? array_merge($data, ['shipping' => $quote['shipping']])
                            : $data,
                        self::readPayloadString($data, ['payer', 'from'])
                    ),
                    $productSku,
                    (int) $quote['quantity'],
                    (string) $quote['productSubtotalJpyc'],
                    (string) $quote['shippingFeeJpyc'],
                    (string) $quote['discountJpyc'],
                    (string) $quote['totalJpyc'],
                    $idempotencyKey,
                    $idempotencyRef,
                    $merchantOrderId,
                    $clientReferenceId,
                    $txHash,
                    self::readPayloadString($data, ['payer', 'from']),
                    self::readPayloadString($data, ['itemName', 'item_name'])
                );
            }

            $product = ProductResolver::findBySku($productSku);
            if (!$product instanceof \WC_Product) {
                return new WP_REST_Response(['error' => 'product_not_found'], 404);
            }

            $quoteError = self::validateQuote(
                $quote,
                $data,
                $productSku,
                $amount,
                $product,
                $claim !== null
            );
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
            if (self::unitPriceFromSubtotal($productSubtotal, $quantity) === null) {
                return new WP_REST_Response(['error' => 'quote_amount_mismatch'], 400);
            }
            $payer = self::readPayloadString($data, ['payer', 'from']);
            $itemName = self::readPayloadString($data, ['itemName', 'item_name']);
            $addressData = $data;
            if (is_array($quote['shipping'] ?? null)) {
                $addressData['shipping'] = $quote['shipping'];
            }
            $address = self::x402Address($addressData, $payer);

            if ($claim === null) {
                $claimResult = QuoteStore::claimUnused($quoteId, $owner);
                if ($claimResult['status'] === 'conflict') {
                    return new WP_REST_Response(['error' => 'quote_claim_conflict'], 409);
                }
                if ($claimResult['status'] === 'used') {
                    return new WP_REST_Response(['error' => 'quote_already_used'], 409);
                }
                if ($claimResult['status'] === 'missing' || $claimResult['claim'] === null) {
                    return self::retryableX402Response('quote_claim_failed');
                }
                $claim = $claimResult['claim'];
            }

            $claimOrderId = (int) ($claim['orderId'] ?? 0);
            if ($existingOrder instanceof \WC_Order) {
                if ($claimOrderId > 0 && $claimOrderId !== $existingOrder->get_id()) {
                    return new WP_REST_Response(['error' => 'quote_claim_order_conflict'], 409);
                }
                if ($claimOrderId === 0 && !QuoteStore::attachOrder(
                    $quoteId,
                    (string) $claim['claimToken'],
                    $existingOrder->get_id()
                )) {
                    return self::retryableX402Response('quote_claim_attach_failed');
                }
                $claim['orderId'] = $existingOrder->get_id();
            } elseif ($claimOrderId > 0) {
                $claimedOrder = wc_get_order($claimOrderId);
                if (!$claimedOrder instanceof \WC_Order) {
                    return self::retryableX402Response('quote_claim_order_missing', [
                        'recoveryPending' => true,
                    ]);
                }
                $existingOrder = $claimedOrder;
            }

            return self::fulfillClaimedX402Order(
                $existingOrder instanceof \WC_Order ? $existingOrder : null,
                $claim,
                $quote,
                $product,
                $address,
                $productSku,
                $quantity,
                $productSubtotal,
                $shippingFee,
                $discount,
                $total,
                $idempotencyKey,
                $idempotencyRef,
                $merchantOrderId,
                $clientReferenceId,
                $txHash,
                $payer,
                $itemName
            );
        } finally {
            self::releaseLockKey($quoteLockKey, $quoteLockToken);
            self::releaseLockKey($idempotencyLockKey, $idempotencyLockToken);
        }
    }

    /**
     * @param array<string,mixed> $claim
     * @param array<string,string> $address
     */
    private static function createAndAttachUnquotedOrderSkeleton(
        array $claim,
        \WC_Product $product,
        array $address,
        string $productSku,
        string $amount,
        string $idempotencyKey,
        string $merchantOrderId,
        string $clientReferenceId,
        string $txHash,
        string $payer,
        string $itemName
    ): ?\WC_Order {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'query')) {
            return null;
        }
        $claimToken = (string) ($claim['claimToken'] ?? '');
        if ($claimToken === '') {
            return null;
        }

        $transactionStarted = false;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control must use the active WooCommerce DB connection.
            if ($wpdb->query('START TRANSACTION') === false) {
                return null;
            }
            $transactionStarted = true;
            $order = wc_create_order(['created_via' => 'uniple-x402']);
            if (!$order instanceof \WC_Order || $order->get_id() < 1) {
                throw new \RuntimeException('unquoted_order_skeleton_create_failed');
            }
            $order->set_payment_method(Plugin::PLUGIN_ID);
            $order->set_payment_method_title(__('JPYC決済 (uniple checkout)', 'uniple-checkout-for-woocommerce'));
            $order->set_address($address, 'billing');
            $order->set_address($address, 'shipping');
            $order->add_product($product, 1, [
                'name' => $itemName !== '' ? $itemName : $product->get_name(),
                'subtotal' => $amount,
                'total' => $amount,
            ]);
            $order->set_currency('JPY');
            $order->set_total((float) $amount);
            $order->update_meta_data('_uniple_x402_claim_token', $claimToken);
            $order->update_meta_data('_uniple_x402_payload_hash', (string) ($claim['payloadHash'] ?? ''));
            $order->update_meta_data('_uniple_x402_idempotency_key', $idempotencyKey);
            $order->update_meta_data('_uniple_x402_product_sku', $productSku);
            $order->update_meta_data('_uniple_x402_quote_id', '');
            $order->update_meta_data('_uniple_x402_quantity', '1');
            $order->update_meta_data('_uniple_x402_product_subtotal_jpyc', $amount);
            $order->update_meta_data('_uniple_x402_shipping_fee_jpyc', '0');
            $order->update_meta_data('_uniple_x402_discount_jpyc', '0');
            $order->update_meta_data('_uniple_x402_total_jpyc', $amount);
            $order->update_meta_data('_uniple_x402_merchant_order_id', $merchantOrderId);
            $order->update_meta_data('_uniple_x402_client_reference_id', $clientReferenceId);
            $order->update_meta_data('_uniple_x402_tx_hash', $txHash);
            $order->update_meta_data('_uniple_x402_payer', $payer);
            $order->save();

            if (!QuoteStore::attachUnquotedOrder($idempotencyKey, $claimToken, $order->get_id())) {
                throw new \RuntimeException('unquoted_claim_attach_failed');
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits the atomic order/claim transaction above.
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('unquoted_order_skeleton_commit_failed');
            }
            $transactionStarted = false;

            return $order;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back the atomic order/claim transaction above.
                $wpdb->query('ROLLBACK');
            }
            wc_get_logger()->error(
                '[uniple-checkout] unquoted x402 skeleton transaction rolled back: '.$e->getMessage(),
                ['source' => 'uniple-checkout', 'product_sku' => $productSku, 'claim_token' => $claimToken]
            );

            return null;
        }
    }

    /**
     * @param array<string,mixed> $claim
     * @param array<string,mixed> $data
     */
    private static function fulfillUnquotedX402Order(
        ?\WC_Order $order,
        array $claim,
        ?\WC_Product $product,
        array $data,
        string $productSku,
        string $amount,
        string $idempotencyKey,
        string $idempotencyRef,
        string $merchantOrderId,
        string $clientReferenceId,
        string $txHash
    ): WP_REST_Response {
        $claimToken = (string) ($claim['claimToken'] ?? '');
        if ($claimToken === '') {
            return self::retryableX402Response('x402_claim_invalid');
        }
        $payer = self::readPayloadString($data, ['payer', 'from']);
        $itemName = self::readPayloadString($data, ['itemName', 'item_name']);

        try {
            $isRecovery = $order instanceof \WC_Order;
            if (!$isRecovery) {
                if (!$product instanceof \WC_Product) {
                    return self::retryableX402Response('product_required_before_skeleton');
                }
                $order = self::createAndAttachUnquotedOrderSkeleton(
                    $claim,
                    $product,
                    self::x402Address($data, $payer),
                    $productSku,
                    $amount,
                    $idempotencyKey,
                    $merchantOrderId,
                    $clientReferenceId,
                    $txHash,
                    $payer,
                    $itemName
                );
                if (!$order instanceof \WC_Order) {
                    return self::retryableX402Response('unquoted_order_skeleton_pending', [
                        'recoveryPending' => true,
                    ]);
                }
            }

            if (!QuoteStore::attachUnquotedOrder($idempotencyKey, $claimToken, $order->get_id())) {
                return self::retryableX402Response('x402_claim_attach_pending', [
                    'recoveryPending' => true,
                    'orderId' => $order->get_id(),
                ]);
            }
            if (!self::unquotedOrderMatchesClaim($order, $claim, $productSku, $amount, $txHash)) {
                return new WP_REST_Response(['error' => 'x402_recovery_state_invalid'], 409);
            }
            if (!$order->is_paid()) {
                $order->payment_complete($txHash !== '' ? $txHash : $idempotencyRef);
                $order->save();
            }
            if (!$order->is_paid()) {
                return self::retryableX402Response('x402_payment_finalize_pending', [
                    'recoveryPending' => true,
                    'orderId' => $order->get_id(),
                ]);
            }
            if (!QuoteStore::completeUnquotedClaim($idempotencyKey, $claimToken, $order->get_id())) {
                return self::retryableX402Response('x402_claim_finalize_pending', [
                    'recoveryPending' => true,
                    'orderId' => $order->get_id(),
                ]);
            }
            self::ensureX402OrderNote(
                $order,
                $claimToken,
                $productSku,
                $merchantOrderId,
                $clientReferenceId,
                $txHash,
                $payer,
                '',
                '0',
                $amount
            );

            return self::x402OrderAcknowledgement($order, [
                'x402' => true,
                'duplicate' => $isRecovery,
                'recovered' => $isRecovery,
            ]);
        } catch (\Throwable $e) {
            wc_get_logger()->error(
                '[uniple-checkout] unquoted x402 recovery pending: '.$e->getMessage(),
                ['source' => 'uniple-checkout', 'product_sku' => $productSku, 'claim_token' => $claimToken]
            );

            return self::retryableX402Response('x402_order_outcome_uncertain', [
                'recoveryPending' => true,
                'orderId' => $order instanceof \WC_Order ? $order->get_id() : 0,
            ]);
        }
    }

    /**
     * @param array<string,mixed>  $claim
     * @param array<string,mixed>  $quote
     * @param array<string,string> $address
     */
    private static function createAndAttachClaimedOrderSkeleton(
        array $claim,
        array $quote,
        \WC_Product $product,
        array $address,
        string $productSku,
        int $quantity,
        string $productSubtotal,
        string $shippingFee,
        string $discount,
        string $total,
        string $idempotencyKey,
        string $merchantOrderId,
        string $clientReferenceId,
        string $txHash,
        string $payer,
        string $itemName
    ): ?\WC_Order {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'query')) {
            return null;
        }
        $quoteId = (string) ($quote['quoteId'] ?? '');
        $claimToken = (string) ($claim['claimToken'] ?? '');
        if ($quoteId === '' || $claimToken === '') {
            return null;
        }

        $transactionStarted = false;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control must use the active WooCommerce DB connection.
            if ($wpdb->query('START TRANSACTION') === false) {
                return null;
            }
            $transactionStarted = true;
            $order = wc_create_order(['created_via' => 'uniple-x402']);
            if (!$order instanceof \WC_Order || $order->get_id() < 1) {
                throw new \RuntimeException('order_skeleton_create_failed');
            }

            $order->set_payment_method(Plugin::PLUGIN_ID);
            $order->set_payment_method_title(__('JPYC決済 (uniple checkout)', 'uniple-checkout-for-woocommerce'));
            $order->set_address($address, 'billing');
            $order->set_address($address, 'shipping');
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
            $order->update_meta_data('_uniple_x402_claim_token', $claimToken);
            $order->update_meta_data('_uniple_x402_idempotency_key', $idempotencyKey);
            $order->update_meta_data('_uniple_x402_product_sku', $productSku);
            $order->update_meta_data('_uniple_x402_quote_id', $quoteId);
            $order->update_meta_data('_uniple_x402_quantity', (string) $quantity);
            $order->update_meta_data('_uniple_x402_product_subtotal_jpyc', $productSubtotal);
            $order->update_meta_data('_uniple_x402_shipping_fee_jpyc', $shippingFee);
            $order->update_meta_data('_uniple_x402_discount_jpyc', $discount);
            $order->update_meta_data('_uniple_x402_total_jpyc', $total);
            $order->update_meta_data('_uniple_x402_quote_source', (string) ($quote['quoteSource'] ?? ''));
            $order->update_meta_data('_uniple_x402_quote_expires_at', (string) ($quote['expiresAt'] ?? ''));
            $order->update_meta_data('_uniple_x402_payload_hash', (string) ($claim['payloadHash'] ?? ''));
            $order->update_meta_data('_uniple_x402_merchant_order_id', $merchantOrderId);
            $order->update_meta_data('_uniple_x402_client_reference_id', $clientReferenceId);
            $order->update_meta_data('_uniple_x402_tx_hash', $txHash);
            $order->update_meta_data('_uniple_x402_payer', $payer);
            $order->save();

            if (!QuoteStore::attachOrder($quoteId, $claimToken, $order->get_id())) {
                throw new \RuntimeException('quote_claim_attach_failed');
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits the atomic order/claim transaction above.
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('order_skeleton_commit_failed');
            }
            $transactionStarted = false;
            QuoteStore::flushCaches($quoteId);

            return $order;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back the atomic order/claim transaction above.
                $wpdb->query('ROLLBACK');
            }
            QuoteStore::flushCaches($quoteId);
            wc_get_logger()->error(
                '[uniple-checkout] x402 skeleton transaction rolled back: '.$e->getMessage(),
                [
                    'source' => 'uniple-checkout',
                    'quote_id' => $quoteId,
                    'claim_token' => $claimToken,
                ]
            );

            return null;
        }
    }

    /**
     * @param array<string,mixed>  $claim
     * @param array<string,mixed>  $quote
     * @param array<string,string> $address
     */
    private static function fulfillClaimedX402Order(
        ?\WC_Order $order,
        array $claim,
        array $quote,
        ?\WC_Product $product,
        array $address,
        string $productSku,
        int $quantity,
        string $productSubtotal,
        string $shippingFee,
        string $discount,
        string $total,
        string $idempotencyKey,
        string $idempotencyRef,
        string $merchantOrderId,
        string $clientReferenceId,
        string $txHash,
        string $payer,
        string $itemName
    ): WP_REST_Response {
        $quoteId = (string) $quote['quoteId'];
        $claimToken = (string) ($claim['claimToken'] ?? '');
        if ($claimToken === '') {
            return self::retryableX402Response('quote_claim_invalid');
        }

        try {
            $isRecovery = $order instanceof \WC_Order;
            if (!$isRecovery) {
                if (!$product instanceof \WC_Product) {
                    return self::retryableX402Response('product_required_before_skeleton');
                }
                $created = self::createAndAttachClaimedOrderSkeleton(
                    $claim,
                    $quote,
                    $product,
                    $address,
                    $productSku,
                    $quantity,
                    $productSubtotal,
                    $shippingFee,
                    $discount,
                    $total,
                    $idempotencyKey,
                    $merchantOrderId,
                    $clientReferenceId,
                    $txHash,
                    $payer,
                    $itemName
                );
                if (!$created instanceof \WC_Order) {
                    return self::retryableX402Response('order_skeleton_transaction_pending', [
                        'recoveryPending' => true,
                    ]);
                }
                $order = $created;
            }

            $claimOrderId = (int) ($claim['orderId'] ?? 0);
            if ($claimOrderId > 0 && $claimOrderId !== $order->get_id()) {
                return new WP_REST_Response(['error' => 'quote_claim_order_conflict'], 409);
            }
            if ($claimOrderId === 0 && !QuoteStore::attachOrder($quoteId, $claimToken, $order->get_id())) {
                return self::retryableX402Response('quote_claim_attach_failed', [
                    'recoveryPending' => true,
                    'orderId' => $order->get_id(),
                ]);
            }

            $storedIdempotencyKey = (string) $order->get_meta('_uniple_x402_idempotency_key', true);
            $storedTxHash = (string) $order->get_meta('_uniple_x402_tx_hash', true);
            $storedClaimToken = (string) $order->get_meta('_uniple_x402_claim_token', true);
            if ($storedIdempotencyKey !== '' && !hash_equals($storedIdempotencyKey, $idempotencyKey)) {
                return new WP_REST_Response(['error' => 'x402_idempotency_conflict'], 409);
            }
            if ($storedTxHash !== '' && !PaidQuoteRedemption::transactionMatches($storedTxHash, $txHash)) {
                return new WP_REST_Response(['error' => 'x402_idempotency_conflict'], 409);
            }
            if ($storedClaimToken !== '' && !hash_equals($storedClaimToken, $claimToken)) {
                return new WP_REST_Response(['error' => 'quote_claim_order_conflict'], 409);
            }

            if (!self::orderMatchesClaim(
                $order,
                $claim,
                $idempotencyKey,
                $quote,
                $productSku,
                $txHash,
                $quantity,
                $productSubtotal,
                $shippingFee,
                $discount,
                $total
            )) {
                return new WP_REST_Response(['error' => 'x402_recovery_state_invalid'], 409);
            }

            if ($order->is_paid()) {
                if (!QuoteStore::markUsedByClaim($quoteId, $claimToken, $order->get_id())) {
                    return self::retryableX402Response('quote_finalize_pending', [
                        'recoveryPending' => true,
                        'orderId' => $order->get_id(),
                    ]);
                }
                self::ensureX402OrderNote(
                    $order,
                    $claimToken,
                    $productSku,
                    $merchantOrderId,
                    $clientReferenceId,
                    $txHash,
                    $payer,
                    $quoteId,
                    $shippingFee,
                    $total
                );

                return self::x402OrderAcknowledgement($order, [
                    'duplicate' => true,
                    'recovered' => true,
                ]);
            }

            // The full order was committed atomically with the claim. Retry
            // only performs the idempotent payment transition; it never
            // needs the product catalog row again.
            if (!$order->is_paid()) {
                $order->payment_complete($txHash !== '' ? $txHash : $idempotencyRef);
            }
            $order->save();
            if (!$order->is_paid()) {
                return self::retryableX402Response('x402_payment_finalize_pending', [
                    'recoveryPending' => true,
                    'orderId' => $order->get_id(),
                ]);
            }
            if (!QuoteStore::markUsedByClaim($quoteId, $claimToken, $order->get_id())) {
                return self::retryableX402Response('quote_finalize_pending', [
                    'recoveryPending' => true,
                    'orderId' => $order->get_id(),
                ]);
            }
            self::ensureX402OrderNote(
                $order,
                $claimToken,
                $productSku,
                $merchantOrderId,
                $clientReferenceId,
                $txHash,
                $payer,
                $quoteId,
                $shippingFee,
                $total
            );

            return self::x402OrderAcknowledgement($order, [
                'x402' => true,
                'recovered' => $isRecovery,
            ]);
        } catch (\Throwable $e) {
            wc_get_logger()->error(
                '[uniple-checkout] claimed x402 order outcome uncertain: '.$e->getMessage(),
                [
                    'source' => 'uniple-checkout',
                    'product_sku' => $productSku,
                    'quote_id' => $quoteId,
                    'claim_token' => $claimToken,
                    'order_id' => $order instanceof \WC_Order ? $order->get_id() : 0,
                ]
            );

            return self::retryableX402Response('x402_order_outcome_uncertain', [
                'recoveryPending' => true,
                'orderId' => $order instanceof \WC_Order ? $order->get_id() : 0,
            ]);
        }
    }

    private static function orderMatchesClaim(
        \WC_Order $order,
        array $claim,
        string $idempotencyKey,
        array $quote,
        string $productSku,
        string $txHash,
        int $quantity,
        string $productSubtotal,
        string $shippingFee,
        string $discount,
        string $total
    ): bool {
        $claimToken = (string) ($claim['claimToken'] ?? '');
        $quoteId = (string) ($quote['quoteId'] ?? '');
        $orderTotal = self::normalizeQuoteAmount($order->get_total());

        return hash_equals($claimToken, (string) $order->get_meta('_uniple_x402_claim_token', true))
            && hash_equals($idempotencyKey, (string) $order->get_meta('_uniple_x402_idempotency_key', true))
            && hash_equals($quoteId, (string) $order->get_meta('_uniple_x402_quote_id', true))
            && hash_equals($productSku, (string) $order->get_meta('_uniple_x402_product_sku', true))
            && PaidQuoteRedemption::transactionMatches(
                (string) $order->get_meta('_uniple_x402_tx_hash', true),
                $txHash
            )
            && hash_equals((string) $quantity, (string) $order->get_meta('_uniple_x402_quantity', true))
            && hash_equals($productSubtotal, (string) $order->get_meta('_uniple_x402_product_subtotal_jpyc', true))
            && hash_equals($shippingFee, (string) $order->get_meta('_uniple_x402_shipping_fee_jpyc', true))
            && hash_equals($discount, (string) $order->get_meta('_uniple_x402_discount_jpyc', true))
            && hash_equals($total, (string) $order->get_meta('_uniple_x402_total_jpyc', true))
            && hash_equals((string) ($quote['quoteSource'] ?? ''), (string) $order->get_meta('_uniple_x402_quote_source', true))
            && hash_equals((string) ($quote['expiresAt'] ?? ''), (string) $order->get_meta('_uniple_x402_quote_expires_at', true))
            && hash_equals((string) ($claim['payloadHash'] ?? ''), (string) $order->get_meta('_uniple_x402_payload_hash', true))
            && $orderTotal === $total;
    }

    private static function ensureX402OrderNote(
        \WC_Order $order,
        string $claimToken,
        string $productSku,
        string $merchantOrderId,
        string $clientReferenceId,
        string $txHash,
        string $payer,
        string $quoteId,
        string $shippingFee,
        string $total
    ): void {
        if (hash_equals($claimToken, (string) $order->get_meta('_uniple_x402_note_claim_token', true))) {
            return;
        }
        $order->add_order_note(self::x402OrderNote(
            $productSku,
            $merchantOrderId,
            $clientReferenceId,
            $txHash,
            $payer,
            $quoteId,
            $shippingFee,
            $total
        ));
        $order->update_meta_data('_uniple_x402_note_claim_token', $claimToken);
        $order->save();
    }

    /**
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $data
     */
    private static function claimPayloadHash(array $quote, array $data, string $productSku, string $amount): string
    {
        $validBefore = null;
        foreach (['paymentAuthorizationValidBefore', 'payment_authorization_valid_before'] as $key) {
            if (array_key_exists($key, $data)) {
                $validBefore = $data[$key];
                break;
            }
        }
        $canonical = json_encode([
            'quoteId' => (string) ($quote['quoteId'] ?? ''),
            'productSku' => $productSku,
            'quantity' => (int) ($quote['quantity'] ?? 0),
            'productSubtotalJpyc' => (string) ($quote['productSubtotalJpyc'] ?? ''),
            'shippingFeeJpyc' => (string) ($quote['shippingFeeJpyc'] ?? ''),
            'discountJpyc' => (string) ($quote['discountJpyc'] ?? ''),
            'totalJpyc' => (string) ($quote['totalJpyc'] ?? ''),
            'quoteSource' => (string) ($quote['quoteSource'] ?? ''),
            'quoteExpiresAt' => (string) ($quote['expiresAt'] ?? ''),
            'amountJpyc' => $amount,
            'paymentAuthorizationValidBefore' => $validBefore,
        ], JSON_UNESCAPED_SLASHES);

        return hash('sha256', is_string($canonical) ? $canonical : serialize($quote));
    }

    /** @param array<string,mixed> $data */
    private static function validateUnquotedPayload(array $data, string $amount): ?string
    {
        $quantity = self::readPayloadString($data, ['quantity', 'qty']);
        if ($quantity !== '' && $quantity !== '1') {
            return 'x402_unquoted_quantity_mismatch';
        }
        $checks = [
            [['productSubtotalJpyc', 'product_subtotal_jpyc'], $amount, 'x402_unquoted_product_subtotal_mismatch'],
            [['shippingFeeJpyc', 'shipping_fee_jpyc'], '0', 'x402_unquoted_shipping_mismatch'],
            [['discountJpyc', 'discount_jpyc'], '0', 'x402_unquoted_discount_mismatch'],
            [['totalJpyc', 'total_jpyc'], $amount, 'x402_unquoted_total_mismatch'],
        ];
        foreach ($checks as [$keys, $expected, $error]) {
            $actual = self::readPayloadAmount($data, $keys);
            if ($actual !== null && $actual !== $expected) {
                return $error;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $data */
    private static function unquotedPayloadHash(array $data, string $productSku, string $amount): string
    {
        $canonical = json_encode([
            'productSku' => $productSku,
            'quantity' => 1,
            'productSubtotalJpyc' => $amount,
            'shippingFeeJpyc' => '0',
            'discountJpyc' => '0',
            'totalJpyc' => $amount,
            'merchantOrderId' => self::readPayloadString($data, ['merchantOrderId', 'merchant_order_id']),
            'clientReferenceId' => self::readPayloadString($data, ['clientReferenceId', 'client_reference_id']),
            'payer' => self::readPayloadString($data, ['payer', 'from']),
            'itemName' => self::readPayloadString($data, ['itemName', 'item_name']),
            'shipping' => self::x402ShippingPayload($data),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($canonical) ? $canonical : serialize($data));
    }

    /** @param array<string,mixed> $claim */
    private static function unquotedOrderMatchesClaim(
        \WC_Order $order,
        array $claim,
        string $productSku,
        string $amount,
        string $txHash
    ): bool {
        $storedClaimToken = (string) $order->get_meta('_uniple_x402_claim_token', true);
        $storedPayloadHash = (string) $order->get_meta('_uniple_x402_payload_hash', true);

        return ($storedClaimToken === '' || hash_equals((string) ($claim['claimToken'] ?? ''), $storedClaimToken))
            && ($storedPayloadHash === '' || hash_equals((string) ($claim['payloadHash'] ?? ''), $storedPayloadHash))
            && hash_equals((string) ($claim['idempotencyKey'] ?? ''), (string) $order->get_meta('_uniple_x402_idempotency_key', true))
            && hash_equals($productSku, (string) $order->get_meta('_uniple_x402_product_sku', true))
            && hash_equals('', (string) $order->get_meta('_uniple_x402_quote_id', true))
            && PaidQuoteRedemption::transactionMatches(
                (string) $order->get_meta('_uniple_x402_tx_hash', true),
                $txHash
            )
            && hash_equals('1', (string) $order->get_meta('_uniple_x402_quantity', true))
            && hash_equals($amount, (string) $order->get_meta('_uniple_x402_product_subtotal_jpyc', true))
            && hash_equals('0', (string) $order->get_meta('_uniple_x402_shipping_fee_jpyc', true))
            && hash_equals('0', (string) $order->get_meta('_uniple_x402_discount_jpyc', true))
            && hash_equals($amount, (string) $order->get_meta('_uniple_x402_total_jpyc', true))
            && self::normalizeQuoteAmount($order->get_total()) === $amount;
    }

    /**
     * Strong duplicate identity: persisted order, incoming webhook, and local
     * q1 must all agree before any second quote can be claimed or attached.
     *
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $data
     */
    private static function quotedOrderMatchesPayload(
        \WC_Order $order,
        array $quote,
        array $data,
        string $productSku,
        string $amount
    ): bool {
        $quantity = self::readPayloadString($data, ['quantity', 'qty']);
        $subtotal = self::readPayloadAmount($data, ['productSubtotalJpyc', 'product_subtotal_jpyc']);
        $shipping = self::readPayloadAmount($data, ['shippingFeeJpyc', 'shipping_fee_jpyc']);
        $discount = self::readPayloadAmount($data, ['discountJpyc', 'discount_jpyc']);
        $total = self::readPayloadAmount($data, ['totalJpyc', 'total_jpyc']);
        $payloadHash = self::claimPayloadHash($quote, $data, $productSku, $amount);

        return $quantity !== ''
            && ctype_digit($quantity)
            && (int) $quantity === (int) ($quote['quantity'] ?? 0)
            && $subtotal === (string) ($quote['productSubtotalJpyc'] ?? '')
            && $shipping === (string) ($quote['shippingFeeJpyc'] ?? '')
            && $discount === (string) ($quote['discountJpyc'] ?? '')
            && $total === (string) ($quote['totalJpyc'] ?? '')
            && hash_equals((string) ($quote['quoteId'] ?? ''), self::readPayloadString($data, ['quoteId', 'quote_id']))
            && hash_equals((string) ($quote['quoteSource'] ?? ''), self::readPayloadString($data, ['quoteSource', 'quote_source']))
            && hash_equals((string) ($quote['expiresAt'] ?? ''), self::readPayloadString($data, ['quoteExpiresAt', 'quote_expires_at']))
            && hash_equals($productSku, (string) $order->get_meta('_uniple_x402_product_sku', true))
            && hash_equals((string) ($quote['quoteId'] ?? ''), (string) $order->get_meta('_uniple_x402_quote_id', true))
            && hash_equals((string) ($quote['quantity'] ?? ''), (string) $order->get_meta('_uniple_x402_quantity', true))
            && hash_equals((string) ($quote['productSubtotalJpyc'] ?? ''), (string) $order->get_meta('_uniple_x402_product_subtotal_jpyc', true))
            && hash_equals((string) ($quote['shippingFeeJpyc'] ?? ''), (string) $order->get_meta('_uniple_x402_shipping_fee_jpyc', true))
            && hash_equals((string) ($quote['discountJpyc'] ?? ''), (string) $order->get_meta('_uniple_x402_discount_jpyc', true))
            && hash_equals((string) ($quote['totalJpyc'] ?? ''), (string) $order->get_meta('_uniple_x402_total_jpyc', true))
            && hash_equals((string) ($quote['quoteSource'] ?? ''), (string) $order->get_meta('_uniple_x402_quote_source', true))
            && hash_equals((string) ($quote['expiresAt'] ?? ''), (string) $order->get_meta('_uniple_x402_quote_expires_at', true))
            && hash_equals($payloadHash, (string) $order->get_meta('_uniple_x402_payload_hash', true))
            && self::normalizeQuoteAmount($order->get_total()) === (string) ($quote['totalJpyc'] ?? '');
    }

    private static function retryableX402Response(string $error, array $extra = []): WP_REST_Response
    {
        return new WP_REST_Response(
            array_merge(['error' => $error, 'retryable' => true], $extra),
            503,
            ['Retry-After' => '5']
        );
    }

    /**
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $data
     */
    private static function validateQuote(
        array $quote,
        array $data,
        string $productSku,
        string $amount,
        \WC_Product $product,
        bool $claimed = false
    ): ?string
    {
        if (!$claimed) {
            if (!empty($quote['usedAt'])) {
                return 'quote_already_used';
            }
            $now = time();
            $expiryError = PaidQuoteRedemption::expiryValidationError($quote, $data, $now);
            if ($expiryError !== null) {
                return $expiryError;
            }
        }
        if ((string) ($quote['productSku'] ?? '') !== $productSku || (int) ($quote['productId'] ?? 0) !== (int) $product->get_id()) {
            return 'quote_product_mismatch';
        }
        if ((string) ($quote['totalJpyc'] ?? '') !== $amount) {
            return 'quote_amount_mismatch';
        }

        $quantity = self::readPayloadString($data, ['quantity', 'qty']);
        if ($quantity === '') {
            return 'quote_quantity_missing';
        }
        if (!ctype_digit($quantity) || (int) $quantity !== (int) $quote['quantity']) {
            return 'quote_quantity_mismatch';
        }

        $subtotal = self::readPayloadAmount($data, ['productSubtotalJpyc', 'product_subtotal_jpyc']);
        if ($subtotal === null) {
            return 'quote_product_subtotal_missing';
        }
        if ($subtotal !== (string) $quote['productSubtotalJpyc']) {
            return 'quote_product_subtotal_mismatch';
        }
        $shippingFee = self::readPayloadAmount($data, ['shippingFeeJpyc', 'shipping_fee_jpyc']);
        if ($shippingFee === null) {
            return 'quote_shipping_fee_missing';
        }
        if ($shippingFee !== (string) $quote['shippingFeeJpyc']) {
            return 'quote_shipping_fee_mismatch';
        }
        $discount = self::readPayloadAmount($data, ['discountJpyc', 'discount_jpyc']);
        if ($discount === null) {
            return 'quote_discount_missing';
        }
        if ($discount !== (string) $quote['discountJpyc']) {
            return 'quote_discount_mismatch';
        }
        $total = self::readPayloadAmount($data, ['totalJpyc', 'total_jpyc']);
        if ($total === null) {
            return 'quote_total_missing';
        }
        if ($total !== (string) $quote['totalJpyc']) {
            return 'quote_total_mismatch';
        }
        $quoteSource = self::readPayloadString($data, ['quoteSource', 'quote_source']);
        if ($quoteSource === '') {
            return 'quote_source_missing';
        }
        if (!hash_equals((string) ($quote['quoteSource'] ?? ''), $quoteSource)) {
            return 'quote_source_mismatch';
        }
        $quoteExpiresAt = self::readPayloadString($data, ['quoteExpiresAt', 'quote_expires_at']);
        if ($quoteExpiresAt === '') {
            return 'quote_expires_at_missing';
        }
        if (!hash_equals((string) ($quote['expiresAt'] ?? ''), $quoteExpiresAt)) {
            return 'quote_expires_at_mismatch';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function existingX402OrderResponse(
        string $idempotencyKey,
        string $txHash,
        string $productSku,
        string $amount,
        array $data
    ): ?WP_REST_Response {
        $order = self::findExistingX402Order($idempotencyKey);
        if (!$order instanceof \WC_Order) {
            return null;
        }
        $storedTxHash = (string) $order->get_meta('_uniple_x402_tx_hash', true);
        if (!PaidQuoteRedemption::transactionMatches($storedTxHash, $txHash)) {
            return new WP_REST_Response(['error' => 'x402_idempotency_conflict'], 409);
        }
        $quantity = self::readPayloadString($data, ['quantity', 'qty']);
        $quantity = $quantity === '' ? '1' : $quantity;
        $exact = hash_equals($productSku, (string) $order->get_meta('_uniple_x402_product_sku', true))
            && hash_equals('', (string) $order->get_meta('_uniple_x402_quote_id', true))
            && hash_equals($amount, (string) $order->get_meta('_uniple_x402_total_jpyc', true))
            && hash_equals($quantity, (string) $order->get_meta('_uniple_x402_quantity', true))
            && hash_equals($amount, (string) $order->get_meta('_uniple_x402_product_subtotal_jpyc', true))
            && hash_equals('0', (string) $order->get_meta('_uniple_x402_shipping_fee_jpyc', true))
            && hash_equals('0', (string) $order->get_meta('_uniple_x402_discount_jpyc', true));
        if (!$exact) {
            return new WP_REST_Response(['error' => 'x402_duplicate_payload_mismatch'], 409);
        }
        if (!$order->is_paid()) {
            return self::retryableX402Response('x402_order_recovery_pending', [
                'recoveryPending' => true,
                'orderId' => $order->get_id(),
            ]);
        }

        return self::x402OrderAcknowledgement($order, ['duplicate' => true]);
    }

    /**
     * The WooCommerce order-received URL contains WooCommerce's own order key,
     * so a buyer can open the standard completion page without sharing a login
     * session and without exposing the key as a separate uniple field.
     *
     * @param array<string,mixed> $extra
     */
    private static function x402OrderAcknowledgement(\WC_Order $order, array $extra = []): WP_REST_Response
    {
        $completionUrl = (string) $order->get_checkout_order_received_url();
        $parts = wp_parse_url($completionUrl);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
        ) {
            return self::retryableX402Response('x402_completion_url_unavailable');
        }

        return new WP_REST_Response(array_merge([
            'ok' => true,
            'orderId' => $order->get_id(),
            'completionUrl' => $completionUrl,
        ], $extra), 200);
    }

    private static function findExistingX402Order(string $idempotencyKey): ?\WC_Order
    {
        $existing = wc_get_orders([
            'limit' => 1,
            'return' => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required idempotency lookup, bounded to one WooCommerce order.
            'meta_key' => '_uniple_x402_idempotency_key',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required idempotency lookup, bounded to one WooCommerce order.
            'meta_value' => $idempotencyKey,
        ]);
        if (!is_array($existing) || count($existing) === 0) {
            return null;
        }

        $orderId = (int) $existing[0];
        $order = wc_get_order($orderId);

        return $order instanceof \WC_Order ? $order : null;
    }

    /**
     * Atomic lock via wp_options.
     *
     * `add_option` は INSERT ... 失敗で false を返す原子操作なので、 set_transient より
     * 強い lock になる。 ttl 経過した stale lock は判定後 delete + 再 add で奪取。
     */
    private static function acquireLock(int $orderId): ?string
    {
        return self::acquireLockKey((string) $orderId);
    }

    private static function acquireLockKey(string $suffix): ?string
    {
        $key = self::lockOptionKey($suffix);
        $now = time();
        $token = bin2hex(random_bytes(16));
        $payload = ['token' => $token, 'acquiredAt' => $now];

        if (add_option($key, $payload, '', false)) {
            return $token;
        }

        $existing = self::readLockOptionAuthoritative($key);
        $acquiredAt = is_array($existing)
            ? (int) ($existing['acquiredAt'] ?? 0)
            : (int) $existing;
        if ($acquiredAt > 0 && ($now - $acquiredAt) > self::LOCK_TTL_SECONDS) {
            if (!self::compareAndDeleteLockOption($key, $existing)) {
                return null;
            }
            if (add_option($key, $payload, '', false)) {
                return $token;
            }
        }

        return null;
    }

    private static function releaseLock(int $orderId, string $token): void
    {
        self::releaseLockKey((string) $orderId, $token);
    }

    private static function releaseLockKey(string $suffix, string $token): void
    {
        $key = self::lockOptionKey($suffix);
        $current = self::readLockOptionAuthoritative($key);
        if (!is_array($current) || !hash_equals((string) ($current['token'] ?? ''), $token)) {
            return;
        }
        self::compareAndDeleteLockOption($key, $current);
    }

    private static function lockOptionKey(string $suffix): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $suffix) ?? '';
        if (strlen($safe) > 80) {
            $safe = hash('sha256', $safe);
        }

        return self::LOCK_OPTION_PREFIX.$safe;
    }

    private static function readLockOptionAuthoritative(string $key): mixed
    {
        global $wpdb;

        if (
            is_object($wpdb)
            && isset($wpdb->options)
            && method_exists($wpdb, 'get_var')
            && method_exists($wpdb, 'prepare')
        ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypasses stale option cache for an authoritative lock read.
            $raw = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $key
            ));
            if ($raw === null) {
                return null;
            }

            return function_exists('maybe_unserialize') ? maybe_unserialize($raw) : @unserialize((string) $raw);
        }

        return get_option($key, null);
    }

    private static function compareAndDeleteLockOption(string $key, mixed $expected): bool
    {
        global $wpdb;

        if (
            is_object($wpdb)
            && isset($wpdb->options)
            && method_exists($wpdb, 'query')
            && method_exists($wpdb, 'prepare')
        ) {
            $raw = function_exists('maybe_serialize') ? maybe_serialize($expected) : serialize($expected);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-delete prevents releasing another request's lock.
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $key,
                $raw
            ));
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($key, 'options');
            }

            return $deleted === 1;
        }

        if (get_option($key, null) !== $expected) {
            return false;
        }

        return delete_option($key);
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
