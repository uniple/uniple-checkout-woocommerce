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

final class QuoteStore
{
    public const TTL_SECONDS = 900;
    private const OPTION_PREFIX = 'uniple_x402_quote_';
    private const CLAIM_OPTION_PREFIX = 'uniple_x402_quote_claim_';
    private const UNQUOTED_CLAIM_OPTION_PREFIX = 'uniple_x402_unquoted_claim_';

    /**
     * @param array<string,mixed> $quote
     */
    public static function save(array $quote): void
    {
        $key = self::optionKey((string) $quote['quoteId']);
        if (!add_option($key, $quote, '', false)) {
            update_option($key, $quote, false);
        }
        self::flushOptionCache($key);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(string $quoteId): ?array
    {
        $quote = self::readOptionAuthoritative(self::optionKey($quoteId));

        return is_array($quote) ? $quote : null;
    }

    /**
     * Atomically creates a durable owner claim for an unused quote.
     *
     * The quote-specific controller lock serializes the unused check with
     * normal writers. `add_option` is the durable SETNX boundary that still
     * rejects another owner after the 60-second processing lease expires.
     *
     * @param array{idempotencyKey:string,sessionId:string,txHash:string,payloadHash:string} $owner
     *
     * @return array{status:'acquired'|'owned'|'conflict'|'used'|'missing',claim:array<string,mixed>|null}
     */
    public static function claimUnused(string $quoteId, array $owner): array
    {
        $quote = self::find($quoteId);
        if ($quote === null) {
            return ['status' => 'missing', 'claim' => null];
        }
        $existing = self::findClaim($quoteId);
        if ($existing !== null) {
            return [
                'status' => self::claimMatches($existing, $owner) ? 'owned' : 'conflict',
                'claim' => $existing,
            ];
        }
        if (!empty($quote['usedAt'])) {
            return ['status' => 'used', 'claim' => null];
        }

        $now = gmdate(DATE_ATOM);
        $claim = [
            'version' => 1,
            'claimToken' => bin2hex(random_bytes(16)),
            'quoteId' => $quoteId,
            'idempotencyKey' => (string) $owner['idempotencyKey'],
            'sessionId' => (string) $owner['sessionId'],
            'txHash' => strtolower(trim((string) $owner['txHash'])),
            'payloadHash' => (string) $owner['payloadHash'],
            'orderId' => 0,
            'state' => 'claimed',
            'claimedAt' => $now,
            'updatedAt' => $now,
        ];
        if (add_option(self::claimOptionKey($quoteId), $claim, '', false)) {
            self::flushOptionCache(self::claimOptionKey($quoteId));
            return ['status' => 'acquired', 'claim' => $claim];
        }

        $existing = self::findClaim($quoteId);
        if ($existing === null) {
            return ['status' => 'conflict', 'claim' => null];
        }

        return [
            'status' => self::claimMatches($existing, $owner) ? 'owned' : 'conflict',
            'claim' => $existing,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findClaim(string $quoteId): ?array
    {
        $claim = self::readOptionAuthoritative(self::claimOptionKey($quoteId));

        return is_array($claim) ? $claim : null;
    }

    /**
     * @param array<string,mixed> $claim
     * @param array{idempotencyKey:string,sessionId:string,txHash:string,payloadHash:string} $owner
     */
    public static function claimMatches(array $claim, array $owner): bool
    {
        return hash_equals((string) ($claim['idempotencyKey'] ?? ''), (string) $owner['idempotencyKey'])
            && hash_equals((string) ($claim['sessionId'] ?? ''), (string) $owner['sessionId'])
            && hash_equals(strtolower((string) ($claim['txHash'] ?? '')), strtolower(trim((string) $owner['txHash'])))
            && hash_equals((string) ($claim['payloadHash'] ?? ''), (string) $owner['payloadHash']);
    }

    public static function attachOrder(string $quoteId, string $claimToken, int $orderId): bool
    {
        if ($orderId < 1) {
            return false;
        }
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $claim = self::findClaim($quoteId);
            if ($claim === null || !hash_equals((string) ($claim['claimToken'] ?? ''), $claimToken)) {
                return false;
            }
            $currentOrderId = (int) ($claim['orderId'] ?? 0);
            if ($currentOrderId > 0) {
                return $currentOrderId === $orderId;
            }
            $updated = $claim;
            $updated['orderId'] = $orderId;
            $updated['state'] = 'order_created';
            $updated['updatedAt'] = gmdate(DATE_ATOM);
            if (self::compareAndSwapOption(self::claimOptionKey($quoteId), $claim, $updated)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Marks a quote used only for its durable claim/order pair.
     */
    public static function markUsedByClaim(string $quoteId, string $claimToken, int $orderId): bool
    {
        $claim = self::findClaim($quoteId);
        if (
            $claim === null
            || !hash_equals((string) ($claim['claimToken'] ?? ''), $claimToken)
            || (int) ($claim['orderId'] ?? 0) !== $orderId
        ) {
            return false;
        }
        $quote = self::find($quoteId);
        if ($quote === null) {
            return false;
        }
        if (!empty($quote['usedAt'])) {
            return hash_equals((string) ($quote['usedClaimToken'] ?? ''), $claimToken)
                && (int) ($quote['usedOrderId'] ?? 0) === $orderId;
        }

        $updatedQuote = $quote;
        $updatedQuote['usedAt'] = gmdate(DATE_ATOM);
        $updatedQuote['usedClaimToken'] = $claimToken;
        $updatedQuote['usedOrderId'] = $orderId;
        if (!self::compareAndSwapOption(self::optionKey($quoteId), $quote, $updatedQuote)) {
            $racedQuote = self::find($quoteId);

            return $racedQuote !== null
                && hash_equals((string) ($racedQuote['usedClaimToken'] ?? ''), $claimToken)
                && (int) ($racedQuote['usedOrderId'] ?? 0) === $orderId;
        }
        $storedQuote = self::find($quoteId);
        if (
            $storedQuote === null
            || !hash_equals((string) ($storedQuote['usedClaimToken'] ?? ''), $claimToken)
            || (int) ($storedQuote['usedOrderId'] ?? 0) !== $orderId
        ) {
            return false;
        }

        $updatedClaim = $claim;
        $updatedClaim['state'] = 'used';
        $updatedClaim['updatedAt'] = gmdate(DATE_ATOM);
        self::compareAndSwapOption(self::claimOptionKey($quoteId), $claim, $updatedClaim);

        return true;
    }

    /**
     * Releases only a claim that is still provably pre-order.
     */
    public static function releaseUnstartedClaim(string $quoteId, string $claimToken): bool
    {
        $claim = self::findClaim($quoteId);
        if (
            $claim === null
            || !hash_equals((string) ($claim['claimToken'] ?? ''), $claimToken)
            || (int) ($claim['orderId'] ?? 0) > 0
        ) {
            return false;
        }
        return self::compareAndDeleteOption(self::claimOptionKey($quoteId), $claim);
    }

    /**
     * Durable idempotency owner for legacy paid x402 payloads without q1.
     *
     * @param array{idempotencyKey:string,txHash:string,productSku:string,amount:string,payloadHash:string} $owner
     *
     * @return array{status:'acquired'|'owned'|'conflict',claim:array<string,mixed>|null}
     */
    public static function claimUnquoted(array $owner): array
    {
        $idempotencyKey = (string) $owner['idempotencyKey'];
        $existing = self::findUnquotedClaim($idempotencyKey);
        if ($existing !== null) {
            return [
                'status' => self::unquotedClaimMatches($existing, $owner) ? 'owned' : 'conflict',
                'claim' => $existing,
            ];
        }

        $now = gmdate(DATE_ATOM);
        $claim = [
            'version' => 1,
            'claimToken' => bin2hex(random_bytes(16)),
            'idempotencyKey' => $idempotencyKey,
            'txHash' => strtolower(trim((string) $owner['txHash'])),
            'productSku' => (string) $owner['productSku'],
            'amount' => (string) $owner['amount'],
            'payloadHash' => (string) $owner['payloadHash'],
            'orderId' => 0,
            'state' => 'claimed',
            'claimedAt' => $now,
            'updatedAt' => $now,
        ];
        $key = self::unquotedClaimOptionKey($idempotencyKey);
        if (add_option($key, $claim, '', false)) {
            self::flushOptionCache($key);

            return ['status' => 'acquired', 'claim' => $claim];
        }

        $existing = self::findUnquotedClaim($idempotencyKey);
        if ($existing === null) {
            return ['status' => 'conflict', 'claim' => null];
        }

        return [
            'status' => self::unquotedClaimMatches($existing, $owner) ? 'owned' : 'conflict',
            'claim' => $existing,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function findUnquotedClaim(string $idempotencyKey): ?array
    {
        $claim = self::readOptionAuthoritative(self::unquotedClaimOptionKey($idempotencyKey));

        return is_array($claim) ? $claim : null;
    }

    /**
     * @param array<string,mixed> $claim
     * @param array{idempotencyKey:string,txHash:string,productSku:string,amount:string,payloadHash:string} $owner
     */
    public static function unquotedClaimMatches(array $claim, array $owner): bool
    {
        return hash_equals((string) ($claim['idempotencyKey'] ?? ''), (string) $owner['idempotencyKey'])
            && hash_equals(strtolower((string) ($claim['txHash'] ?? '')), strtolower(trim((string) $owner['txHash'])))
            && hash_equals((string) ($claim['productSku'] ?? ''), (string) $owner['productSku'])
            && hash_equals((string) ($claim['amount'] ?? ''), (string) $owner['amount'])
            && hash_equals((string) ($claim['payloadHash'] ?? ''), (string) $owner['payloadHash']);
    }

    public static function attachUnquotedOrder(string $idempotencyKey, string $claimToken, int $orderId): bool
    {
        if ($orderId < 1) {
            return false;
        }
        $key = self::unquotedClaimOptionKey($idempotencyKey);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $claim = self::findUnquotedClaim($idempotencyKey);
            if ($claim === null || !hash_equals((string) ($claim['claimToken'] ?? ''), $claimToken)) {
                return false;
            }
            $currentOrderId = (int) ($claim['orderId'] ?? 0);
            if ($currentOrderId > 0) {
                return $currentOrderId === $orderId;
            }
            $updated = $claim;
            $updated['orderId'] = $orderId;
            $updated['state'] = 'order_created';
            $updated['updatedAt'] = gmdate(DATE_ATOM);
            if (self::compareAndSwapOption($key, $claim, $updated)) {
                return true;
            }
        }

        return false;
    }

    public static function completeUnquotedClaim(string $idempotencyKey, string $claimToken, int $orderId): bool
    {
        $key = self::unquotedClaimOptionKey($idempotencyKey);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $claim = self::findUnquotedClaim($idempotencyKey);
            if (
                $claim === null
                || !hash_equals((string) ($claim['claimToken'] ?? ''), $claimToken)
                || (int) ($claim['orderId'] ?? 0) !== $orderId
            ) {
                return false;
            }
            if (($claim['state'] ?? '') === 'used') {
                return true;
            }
            $updated = $claim;
            $updated['state'] = 'used';
            $updated['updatedAt'] = gmdate(DATE_ATOM);
            if (self::compareAndSwapOption($key, $claim, $updated)) {
                return true;
            }
        }

        return false;
    }

    public static function flushCaches(string $quoteId): void
    {
        self::flushOptionCache(self::optionKey($quoteId));
        self::flushOptionCache(self::claimOptionKey($quoteId));
    }

    private static function optionKey(string $quoteId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $quoteId) ?? '';
        if (strlen($safe) > 80) {
            $safe = hash('sha256', $safe);
        }

        return self::OPTION_PREFIX.$safe;
    }

    private static function claimOptionKey(string $quoteId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $quoteId) ?? '';
        if (strlen($safe) > 80) {
            $safe = hash('sha256', $safe);
        }

        return self::CLAIM_OPTION_PREFIX.$safe;
    }

    private static function unquotedClaimOptionKey(string $idempotencyKey): string
    {
        return self::UNQUOTED_CLAIM_OPTION_PREFIX.hash('sha256', $idempotencyKey);
    }

    private static function readOptionAuthoritative(string $key): mixed
    {
        global $wpdb;

        if (
            is_object($wpdb)
            && isset($wpdb->options)
            && method_exists($wpdb, 'get_var')
            && method_exists($wpdb, 'prepare')
        ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypasses stale option cache for an authoritative claim read.
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

    private static function compareAndSwapOption(string $key, mixed $expected, mixed $updated): bool
    {
        global $wpdb;

        if (
            is_object($wpdb)
            && isset($wpdb->options)
            && method_exists($wpdb, 'query')
            && method_exists($wpdb, 'prepare')
        ) {
            $expectedRaw = self::serializeOption($expected);
            $updatedRaw = self::serializeOption($updated);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap is the claim ownership primitive.
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $updatedRaw,
                $key,
                $expectedRaw
            ));
            self::flushOptionCache($key);

            return $changed === 1;
        }

        $current = get_option($key, null);
        if ($current !== $expected) {
            return false;
        }
        update_option($key, $updated, false);

        return true;
    }

    private static function compareAndDeleteOption(string $key, mixed $expected): bool
    {
        global $wpdb;

        if (
            is_object($wpdb)
            && isset($wpdb->options)
            && method_exists($wpdb, 'query')
            && method_exists($wpdb, 'prepare')
        ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-delete prevents deleting another request's claim.
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $key,
                self::serializeOption($expected)
            ));
            self::flushOptionCache($key);

            return $deleted === 1;
        }

        $current = get_option($key, null);
        if ($current !== $expected) {
            return false;
        }

        return delete_option($key);
    }

    private static function serializeOption(mixed $value): string
    {
        return function_exists('maybe_serialize') ? (string) maybe_serialize($value) : serialize($value);
    }

    private static function flushOptionCache(string $key): void
    {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        wp_cache_delete($key, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
