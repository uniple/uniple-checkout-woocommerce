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

namespace Uniple\CheckoutWooCommerce\Rest;

use Uniple\CheckoutWooCommerce\Api\UnipleClient;
use Uniple\CheckoutWooCommerce\Gateway\UnipleGateway;
use Uniple\CheckoutWooCommerce\Webhook\WebhookController;
use Uniple\CheckoutWooCommerce\X402\ProductSync;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class CatalogController
{
    public const ROUTE = '/catalog';

    private const MAX_PRODUCTS = 200;

    public static function registerRoutes(): void
    {
        register_rest_route(
            WebhookController::ROUTE_NAMESPACE,
            self::ROUTE,
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $method = strtoupper($request->get_method());
        $path = self::externalRequestPath();
        if (
            $method !== 'GET'
            || $path === ''
            || !hash_equals(self::catalogEndpointPath(), $path)
        ) {
            return self::response([
                'ok' => false,
                'complete' => false,
                'error' => 'unauthorized',
            ], 401);
        }

        try {
            $gateway = new UnipleGateway();
            $client = new UnipleClient($gateway->clientConfig());
            $authorized = $client->verifyCatalogRequestSignature(
                (string) $request->get_header('X-Uniple-Catalog-Version'),
                (string) $request->get_header('X-Uniple-Catalog-Timestamp'),
                (string) $request->get_header('X-Uniple-Catalog-Nonce'),
                (string) $request->get_header('X-Uniple-Catalog-Signature'),
                $method,
                $path
            );
        } catch (\Throwable $e) {
            return self::response([
                'ok' => false,
                'complete' => false,
                'error' => 'catalog_build_failed',
            ], 500);
        }

        if (!$authorized) {
            return self::response([
                'ok' => false,
                'complete' => false,
                'error' => 'unauthorized',
            ], 401);
        }

        try {
            $catalog = (new ProductSync())->buildCatalog();
            $products = $catalog['products'] ?? null;
            $revision = $catalog['revision'] ?? null;
            if (
                (int) ($catalog['truncated'] ?? 0) > 0
                || (is_array($products) && count($products) > self::MAX_PRODUCTS)
            ) {
                return self::response([
                    'ok' => false,
                    'complete' => false,
                    'error' => 'catalog_too_large',
                    'maxProducts' => self::MAX_PRODUCTS,
                ], 409);
            }
            if (
                !is_array($products)
                || !is_string($revision)
                || !preg_match('/^[a-f0-9]{64}$/', $revision)
            ) {
                throw new \RuntimeException('catalog_snapshot_invalid');
            }

            return self::response([
                'ok' => true,
                'version' => 1,
                'complete' => true,
                'generatedAt' => gmdate('c'),
                'revision' => $revision,
                'products' => array_values($products),
            ], 200);
        } catch (\Throwable $e) {
            return self::response([
                'ok' => false,
                'complete' => false,
                'error' => 'catalog_build_failed',
            ], 500);
        }
    }

    private static function externalRequestPath(): string
    {
        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field((string) wp_unslash($_SERVER['REQUEST_URI']))
            : '';
        $path = wp_parse_url($requestUri, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    private static function catalogEndpointPath(): string
    {
        $path = wp_parse_url(
            rest_url(WebhookController::ROUTE_NAMESPACE.self::ROUTE),
            PHP_URL_PATH
        );

        return is_string($path) ? $path : '';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function response(array $data, int $status): WP_REST_Response
    {
        return new WP_REST_Response(
            $data,
            $status,
            [
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
