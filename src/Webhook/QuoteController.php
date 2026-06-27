<?php
/*
 * uniple checkout for WooCommerce
 * Copyright (C) 2026 uniple inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2,
 * as published by the Free Software Foundation.
 */

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Webhook;

use Uniple\CheckoutWooCommerce\Util\JapaneseAddress;
use Uniple\CheckoutWooCommerce\X402\ProductResolver;
use Uniple\CheckoutWooCommerce\X402\QuoteStore;
use WC_Product;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class QuoteController
{
    public const ROUTE = '/x402/quote';

    public static function registerRoutes(): void
    {
        register_rest_route(
            WebhookController::ROUTE_NAMESPACE,
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
        $payload = json_decode($request->get_body(), true);
        if (!is_array($payload)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        try {
            $quote = self::createQuote($payload);

            return new WP_REST_Response(['ok' => true, 'quote' => $quote], 200);
        } catch (\InvalidArgumentException $e) {
            return new WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            wc_get_logger()->error(
                '[uniple-checkout] x402 quote failed: '.$e->getMessage(),
                ['source' => 'uniple-checkout']
            );

            return new WP_REST_Response(['ok' => false, 'error' => 'quote_failed'], 500);
        }
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private static function createQuote(array $payload): array
    {
        $productSku = self::readString($payload, ['productSku', 'product_sku', 'externalId', 'external_id']);
        if ($productSku === '') {
            throw new \InvalidArgumentException('product_sku_required');
        }

        $product = ProductResolver::findBySku($productSku);
        if (!$product instanceof WC_Product) {
            throw new \InvalidArgumentException('product_not_found');
        }
        if (!ProductResolver::isPurchasable($product)) {
            throw new \InvalidArgumentException('product_not_available');
        }

        $quantity = self::positiveInt($payload, ['quantity', 'qty'], 1);
        if ($quantity < 1 || $quantity > 99) {
            throw new \InvalidArgumentException('invalid_quantity');
        }

        $shipping = self::normalizeShipping(self::shippingPayload($payload));
        $unitPrice = self::normalizeIntegerJpyc(self::taxIncludedPrice($product));
        if ($unitPrice === null || (int) $unitPrice <= 0) {
            throw new \RuntimeException('invalid_product_price');
        }

        $productSubtotal = (string) ((int) $unitPrice * $quantity);
        $shippingQuote = self::shippingFee($product, $quantity, $shipping, $productSubtotal);
        $shippingFee = $shippingQuote['shippingFeeJpyc'];
        $discount = '0';
        $total = (string) ((int) $productSubtotal + (int) $shippingFee);
        if ((int) $total <= 0) {
            throw new \RuntimeException('invalid_total');
        }

        $now = time();
        $quoteId = 'uq_'.bin2hex(random_bytes(16));
        $quote = [
            'quoteId' => $quoteId,
            'productSku' => $productSku,
            'productId' => $product->get_id(),
            'quantity' => $quantity,
            'productSubtotalJpyc' => $productSubtotal,
            'shippingFeeJpyc' => $shippingFee,
            'discountJpyc' => $discount,
            'totalJpyc' => $total,
            'expiresAt' => gmdate(DATE_ATOM, $now + QuoteStore::TTL_SECONDS),
            'createdAt' => gmdate(DATE_ATOM, $now),
            'usedAt' => null,
            'shipping' => $shipping,
            'shippingRateId' => $shippingQuote['rateId'],
            'shippingRateLabel' => $shippingQuote['label'],
            'quoteSource' => 'woocommerce',
        ];
        QuoteStore::save($quote);

        return self::publicQuote($quote);
    }

    /**
     * @param array<string,mixed> $quote
     *
     * @return array<string,mixed>
     */
    public static function publicQuote(array $quote): array
    {
        return [
            'quoteId' => (string) $quote['quoteId'],
            'productSku' => (string) $quote['productSku'],
            'quantity' => (int) $quote['quantity'],
            'productSubtotalJpyc' => (string) $quote['productSubtotalJpyc'],
            'shippingFeeJpyc' => (string) $quote['shippingFeeJpyc'],
            'discountJpyc' => (string) $quote['discountJpyc'],
            'totalJpyc' => (string) $quote['totalJpyc'],
            'expiresAt' => (string) $quote['expiresAt'],
            'shipping' => is_array($quote['shipping'] ?? null) ? $quote['shipping'] : [],
            'quoteSource' => 'woocommerce',
        ];
    }

    private static function taxIncludedPrice(WC_Product $product): string
    {
        $price = (string) $product->get_price();
        if ($price === '') {
            return '';
        }
        if (function_exists('wc_get_price_including_tax')) {
            return (string) wc_get_price_including_tax($product, ['qty' => 1, 'price' => (float) $price]);
        }

        return $price;
    }

    /**
     * @param array<string,string> $shipping
     *
     * @return array{shippingFeeJpyc:string,rateId:string,label:string}
     */
    private static function shippingFee(WC_Product $product, int $quantity, array $shipping, string $productSubtotal): array
    {
        if (!function_exists('WC') || !WC()->shipping()) {
            return ['shippingFeeJpyc' => '0', 'rateId' => '', 'label' => ''];
        }
        self::ensureWooCommerceSession($shipping);

        $lineTotal = (float) $productSubtotal;
        $package = [
            'contents' => [
                $product->get_id().':x402' => [
                    'key' => $product->get_id().':x402',
                    'product_id' => $product->is_type('variation') ? $product->get_parent_id() : $product->get_id(),
                    'variation_id' => $product->is_type('variation') ? $product->get_id() : 0,
                    'variation' => [],
                    'quantity' => $quantity,
                    'data' => $product,
                    'line_total' => $lineTotal,
                    'line_subtotal' => $lineTotal,
                    'line_tax' => 0,
                    'line_subtotal_tax' => 0,
                ],
            ],
            'contents_cost' => $lineTotal,
            'applied_coupons' => [],
            'user' => ['ID' => 0],
            'destination' => [
                'country' => 'JP',
                'state' => $shipping['state'],
                'postcode' => $shipping['postalCode'],
                'city' => $shipping['city'],
                'address' => $shipping['address1'],
                'address_1' => $shipping['address1'],
                'address_2' => $shipping['address2'],
            ],
        ];

        $packages = WC()->shipping()->calculate_shipping([$package]);
        $rates = is_array($packages[0]['rates'] ?? null) ? $packages[0]['rates'] : [];
        if (empty($rates)) {
            return ['shippingFeeJpyc' => '0', 'rateId' => '', 'label' => ''];
        }

        $selected = null;
        $selectedTotal = null;
        foreach ($rates as $rate) {
            if (!is_object($rate) || !method_exists($rate, 'get_cost')) {
                continue;
            }
            $cost = (float) $rate->get_cost();
            $taxes = method_exists($rate, 'get_taxes') ? array_sum(array_map('floatval', (array) $rate->get_taxes())) : 0.0;
            $total = $cost + $taxes;
            if ($selected === null || $total < $selectedTotal) {
                $selected = $rate;
                $selectedTotal = $total;
            }
        }

        if ($selected === null || $selectedTotal === null) {
            return ['shippingFeeJpyc' => '0', 'rateId' => '', 'label' => ''];
        }
        $fee = self::normalizeIntegerJpyc((string) $selectedTotal);
        if ($fee === null) {
            throw new \RuntimeException('invalid_shipping_fee');
        }

        return [
            'shippingFeeJpyc' => $fee,
            'rateId' => method_exists($selected, 'get_id') ? (string) $selected->get_id() : '',
            'label' => method_exists($selected, 'get_label') ? (string) $selected->get_label() : '',
        ];
    }

    /**
     * @param array<string,string> $shipping
     */
    private static function ensureWooCommerceSession(array $shipping): void
    {
        if (WC()->session === null) {
            if (method_exists(WC(), 'initialize_session')) {
                WC()->initialize_session();
            } elseif (class_exists('WC_Session_Handler')) {
                WC()->session = new \WC_Session_Handler();
                WC()->session->init();
            }
        }

        if (WC()->customer === null) {
            WC()->customer = new \WC_Customer(0, true);
        }
        if (WC()->customer instanceof \WC_Customer) {
            WC()->customer->set_shipping_country('JP');
            WC()->customer->set_shipping_state($shipping['state']);
            WC()->customer->set_shipping_postcode($shipping['postalCode']);
            WC()->customer->set_shipping_city($shipping['city']);
            WC()->customer->set_shipping_address($shipping['address1']);
            WC()->customer->set_shipping_address_2($shipping['address2']);
            WC()->customer->set_billing_country('JP');
            WC()->customer->set_billing_state($shipping['state']);
            WC()->customer->set_billing_postcode($shipping['postalCode']);
        }
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private static function shippingPayload(array $payload): array
    {
        foreach (['shipping', 'shippingAddress', 'shipping_address', 'delivery', 'recipient'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $shipping
     *
     * @return array<string,string>
     */
    private static function normalizeShipping(array $shipping): array
    {
        $firstName = self::readString($shipping, ['firstName', 'first_name', 'givenName', 'given_name', 'name02']);
        $lastName = self::readString($shipping, ['lastName', 'last_name', 'familyName', 'family_name', 'name01']);
        $fullName = self::readString($shipping, ['name', 'fullName', 'full_name', 'recipientName', 'recipient_name']);
        if (($firstName === '' || $lastName === '') && $fullName !== '') {
            $parts = preg_split('/\s+/u', $fullName, 2, PREG_SPLIT_NO_EMPTY) ?: [];
            $lastName = $lastName !== '' ? $lastName : (string) ($parts[0] ?? '');
            $firstName = $firstName !== '' ? $firstName : (string) ($parts[1] ?? '');
        }

        $city = self::readString($shipping, ['city', 'municipality', 'ward']);
        $address1 = self::readString($shipping, ['addr01', 'address1', 'address_1', 'addressLine1', 'address_line1', 'line1', 'streetAddress', 'street_address']);
        $address2 = self::readString($shipping, ['addr02', 'address2', 'address_2', 'addressLine2', 'address_line2', 'line2', 'building', 'apartment', 'room']);
        $phone = self::readString($shipping, ['phoneNumber', 'phone_number', 'phone', 'tel', 'telephone']);
        $postcode = self::readString($shipping, ['postalCode', 'postal_code', 'postCode', 'post_code', 'zipCode', 'zip_code', 'zipcode', 'zip']);
        $prefecture = self::normalizePrefName(self::readString($shipping, ['prefecture', 'pref', 'prefName', 'pref_name', 'state', 'province', 'region']));
        $state = self::stateCode($prefecture);
        $address = JapaneseAddress::normalize($prefecture, $city, $address1, $address2);

        if ($firstName === '' || $lastName === '' || $address['address1'] === '' || $phone === '' || $postcode === '' || $state === '') {
            throw new \InvalidArgumentException('shipping_required_field_missing');
        }

        return [
            'name' => trim($lastName.' '.$firstName),
            'firstName' => mb_substr($firstName, 0, 255),
            'lastName' => mb_substr($lastName, 0, 255),
            'email' => mb_substr(self::readString($shipping, ['email', 'mail']), 0, 255),
            'phone' => mb_substr($phone, 0, 32),
            'postalCode' => mb_substr($postcode, 0, 32),
            'prefecture' => $prefecture,
            'state' => $state,
            'city' => mb_substr($address['city'], 0, 255),
            'address1' => mb_substr($address['address1'], 0, 255),
            'address2' => mb_substr($address['address2'], 0, 255),
            'country' => 'JP',
        ];
    }

    private static function stateCode(string $prefecture): string
    {
        if (preg_match('/^JP\d{2}$/', $prefecture)) {
            return $prefecture;
        }
        $states = function_exists('WC') && WC()->countries ? (array) WC()->countries->get_states('JP') : [];
        foreach ($states as $code => $name) {
            if ((string) $name === $prefecture) {
                return (string) $code;
            }
        }

        return '';
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

    /**
     * @param array<string,mixed> $data
     * @param array<int,string>   $keys
     */
    private static function readString(array $data, array $keys): string
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
     * @param array<int,string>   $keys
     */
    private static function positiveInt(array $data, array $keys, int $default): int
    {
        $value = self::readString($data, $keys);
        if ($value === '') {
            return $default;
        }
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('invalid_integer');
        }

        return (int) $value;
    }

    private static function normalizeIntegerJpyc(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false || !is_scalar($value)) {
            return null;
        }
        $s = trim((string) $value);
        if (!preg_match('/^(\d+)(?:\.0{1,6})?$/', $s, $m)) {
            return null;
        }
        $integer = ltrim($m[1], '0');

        return $integer === '' ? '0' : $integer;
    }
}
