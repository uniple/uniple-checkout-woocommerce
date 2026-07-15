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

namespace Uniple\CheckoutWooCommerce\X402;

defined('ABSPATH') || exit;

/**
 * Validation policy for a paid webhook that arrives after its quote expired.
 *
 * HMAC and completed-event verification happen in WebhookController before
 * this policy is evaluated. The EIP-3009 validBefore value proves the payment
 * authorization could only have executed while q1 was still safely valid.
 */
final class PaidQuoteRedemption
{
    public const AUTHORIZATION_QUOTE_SAFETY_SECONDS = 30;

    /**
     * @param array<string,mixed> $quote
     */
    public static function isExpired(array $quote, int $now): bool
    {
        $expiresAt = strtotime((string) ($quote['expiresAt'] ?? ''));

        return $expiresAt === false || $expiresAt <= $now;
    }

    /**
     * Returns null for an unexpired quote or a valid expired paid redemption.
     *
     * @param array<string,mixed> $quote
     * @param array<string,mixed> $data
     */
    public static function expiryValidationError(array $quote, array $data, int $now): ?string
    {
        $expiresAt = strtotime((string) ($quote['expiresAt'] ?? ''));
        if ($expiresAt !== false && $expiresAt > $now) {
            return null;
        }
        if ($expiresAt === false) {
            return 'quote_expired';
        }

        $txHash = self::readString($data, ['txHash', 'tx_hash', 'transactionId', 'transaction_id']);
        if (!preg_match('/^0x[0-9a-fA-F]{64}$/', $txHash)) {
            return 'paid_redemption_tx_hash_missing_or_invalid';
        }

        [$hasValidBefore, $validBefore] = self::readOwnValue(
            $data,
            ['paymentAuthorizationValidBefore', 'payment_authorization_valid_before']
        );
        if (!$hasValidBefore) {
            return 'payment_authorization_valid_before_missing';
        }
        if (!is_int($validBefore) || $validBefore <= 0) {
            return 'payment_authorization_valid_before_invalid';
        }
        if ($validBefore > $expiresAt - self::AUTHORIZATION_QUOTE_SAFETY_SECONDS) {
            return 'payment_authorization_after_quote_expiry';
        }

        $required = [
            [['quantity', 'qty'], 'quote_quantity_missing'],
            [['productSubtotalJpyc', 'product_subtotal_jpyc'], 'quote_product_subtotal_missing'],
            [['shippingFeeJpyc', 'shipping_fee_jpyc'], 'quote_shipping_fee_missing'],
            [['discountJpyc', 'discount_jpyc'], 'quote_discount_missing'],
            [['totalJpyc', 'total_jpyc'], 'quote_total_missing'],
            [['quoteSource', 'quote_source'], 'quote_source_missing'],
            [['quoteExpiresAt', 'quote_expires_at'], 'quote_expires_at_missing'],
        ];
        foreach ($required as [$keys, $error]) {
            if (!self::hasAnyKey($data, $keys)) {
                return $error;
            }
        }

        [, $quantity] = self::readOwnValue($data, ['quantity', 'qty']);
        $quantityString = is_scalar($quantity) ? trim((string) $quantity) : '';
        if (!ctype_digit($quantityString) || (int) $quantityString !== (int) ($quote['quantity'] ?? 0)) {
            return 'quote_quantity_mismatch';
        }

        $amounts = [
            [['productSubtotalJpyc', 'product_subtotal_jpyc'], 'productSubtotalJpyc', 'quote_product_subtotal_mismatch'],
            [['shippingFeeJpyc', 'shipping_fee_jpyc'], 'shippingFeeJpyc', 'quote_shipping_fee_mismatch'],
            [['discountJpyc', 'discount_jpyc'], 'discountJpyc', 'quote_discount_mismatch'],
            [['totalJpyc', 'total_jpyc'], 'totalJpyc', 'quote_total_mismatch'],
        ];
        foreach ($amounts as [$keys, $quoteKey, $error]) {
            [, $value] = self::readOwnValue($data, $keys);
            if (self::normalizeAmount($value) !== (string) ($quote[$quoteKey] ?? '')) {
                return $error;
            }
        }
        if (self::readString($data, ['quoteSource', 'quote_source']) !== (string) ($quote['quoteSource'] ?? '')) {
            return 'quote_source_mismatch';
        }
        if (self::readString($data, ['quoteExpiresAt', 'quote_expires_at']) !== (string) ($quote['expiresAt'] ?? '')) {
            return 'quote_expires_at_mismatch';
        }

        return null;
    }

    public static function transactionMatches(?string $stored, string $incoming): bool
    {
        return strtolower(trim((string) $stored)) === strtolower(trim($incoming));
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
     *
     * @return array{0:bool,1:mixed}
     */
    private static function readOwnValue(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return [true, $data[$key]];
            }
        }

        return [false, null];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string>   $keys
     */
    private static function hasAnyKey(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeAmount(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false || !is_scalar($value)) {
            return null;
        }
        $amount = trim((string) $value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $amount, $matches)) {
            return null;
        }
        $integer = ltrim($matches[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = isset($matches[2]) ? rtrim($matches[2], '0') : '';

        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }
}
