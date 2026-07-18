<?php

declare(strict_types=1);

namespace {
    defined('ABSPATH') || define('ABSPATH', __DIR__.'/wp/');

    final class WP_REST_Request
    {
        /** @var array<string,string> */
        private array $headers = [];

        /** @param array<string,string> $headers */
        public function __construct(
            private string $method,
            array $headers = []
        ) {
            foreach ($headers as $name => $value) {
                $this->headers[strtolower($name)] = $value;
            }
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_header(string $name): string
        {
            return $this->headers[strtolower($name)] ?? '';
        }
    }

    final class WP_REST_Response
    {
        /**
         * @param array<string,mixed>  $data
         * @param array<string,string> $headers
         */
        public function __construct(
            private array $data,
            private int $status,
            private array $headers = []
        ) {
        }

        /** @return array<string,mixed> */
        public function get_data(): array
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        /** @return array<string,string> */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }

    /**
     * @param array<string,mixed> $args
     */
    function register_rest_route(string $namespace, string $route, array $args): bool
    {
        $GLOBALS['catalog_registered_route'] = compact('namespace', 'route', 'args');

        return true;
    }

    function rest_url(string $path = ''): string
    {
        return 'https://shop.example.test/store/wp-json/'.ltrim($path, '/');
    }

    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }

    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function wp_parse_url(string $url, int $component = -1): array|string|int|false|null
    {
        return $component === -1
            ? parse_url($url)
            : parse_url($url, $component);
    }
}

namespace Uniple\CheckoutWooCommerce\Webhook {
    final class WebhookController
    {
        public const ROUTE_NAMESPACE = 'uniple/v1';
    }
}

namespace Uniple\CheckoutWooCommerce\Gateway {
    final class UnipleGateway
    {
        /** @return array<string,string> */
        public function clientConfig(): array
        {
            return [
                'api_key' => 'api-key-must-not-leak',
                'webhook_secret' => 'webhook-secret-must-not-leak',
                'merchant_label' => 'test',
                'api_base_url' => 'https://uniple.io',
                'mode' => 'live',
            ];
        }
    }
}

namespace Uniple\CheckoutWooCommerce\Api {
    final class UnipleClient
    {
        /** @var array<int,array<string,mixed>> */
        public static array $verifyCalls = [];

        /** @param array<string,string> $config */
        public function __construct(array $config)
        {
        }

        public function verifyCatalogRequestSignature(
            string $version,
            string $timestamp,
            string $nonce,
            string $signature,
            string $method,
            string $path,
            ?int $now = null
        ): bool {
            self::$verifyCalls[] = compact(
                'version',
                'timestamp',
                'nonce',
                'signature',
                'method',
                'path',
                'now'
            );

            if (!empty($GLOBALS['catalog_verify_throws'])) {
                throw new \RuntimeException('verification-secret-must-not-leak');
            }

            return !empty($GLOBALS['catalog_verify_result']);
        }
    }
}

namespace Uniple\CheckoutWooCommerce\X402 {
    final class ProductSync
    {
        /** @return array<string,mixed> */
        public function buildCatalog(): array
        {
            ++$GLOBALS['catalog_build_calls'];
            if (!empty($GLOBALS['catalog_build_throws'])) {
                throw new \RuntimeException('catalog-secret-must-not-leak');
            }

            return $GLOBALS['catalog_snapshot'];
        }
    }
}

namespace {
    use Uniple\CheckoutWooCommerce\Api\UnipleClient;
    use Uniple\CheckoutWooCommerce\Rest\CatalogController;

    require_once __DIR__.'/../src/Rest/CatalogController.php';

    function expectSame(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            fwrite(
                STDERR,
                $label.' expected='.var_export($expected, true)
                .' actual='.var_export($actual, true).PHP_EOL
            );
            exit(1);
        }
    }

    function expectTrue(bool $actual, string $label): void
    {
        expectSame(true, $actual, $label);
    }

    /** @return array<string,string> */
    function signedHeaders(): array
    {
        return [
            'X-Uniple-Catalog-Version' => '1',
            'X-Uniple-Catalog-Timestamp' => '2000000000',
            'X-Uniple-Catalog-Nonce' => str_repeat('a', 32),
            'X-Uniple-Catalog-Signature' => 'sha256='.str_repeat('b', 64),
        ];
    }

    /** @return array<string,mixed> */
    function completeSnapshot(): array
    {
        return [
            'products' => [[
                'externalId' => 'woocommerce-product-11',
                'name' => 'Test product',
                'priceJpyc' => '10',
                'active' => true,
                'description' => '',
                'imageUrl' => '',
                'pageUrl' => 'https://shop.example.test/product/test/',
                'taxLabel' => '税込',
                'sortOrder' => 0,
            ]],
            'active' => 1,
            'inactive' => 0,
            'skipped' => 0,
            'truncated' => 0,
            'revision' => str_repeat('c', 64),
        ];
    }

    function resetCatalogTest(): void
    {
        $_SERVER['REQUEST_URI'] = '/store/wp-json/uniple/v1/catalog';
        $GLOBALS['catalog_verify_result'] = true;
        $GLOBALS['catalog_verify_throws'] = false;
        $GLOBALS['catalog_build_throws'] = false;
        $GLOBALS['catalog_build_calls'] = 0;
        $GLOBALS['catalog_snapshot'] = completeSnapshot();
        UnipleClient::$verifyCalls = [];
    }

    function expectSecurityHeaders(WP_REST_Response $response, string $label): void
    {
        $headers = $response->get_headers();
        expectSame(
            'private, no-store, max-age=0',
            $headers['Cache-Control'] ?? null,
            $label.' cache control'
        );
        expectSame(
            'nosniff',
            $headers['X-Content-Type-Options'] ?? null,
            $label.' nosniff'
        );
    }

    CatalogController::registerRoutes();
    $registered = $GLOBALS['catalog_registered_route'] ?? [];
    expectSame('uniple/v1', $registered['namespace'] ?? null, 'route namespace');
    expectSame('/catalog', $registered['route'] ?? null, 'route path');
    expectSame('GET', $registered['args']['methods'] ?? null, 'route method');
    expectSame('__return_true', $registered['args']['permission_callback'] ?? null, 'public permission callback');
    expectSame(
        [CatalogController::class, 'handle'],
        $registered['args']['callback'] ?? null,
        'route callback'
    );

    resetCatalogTest();
    $GLOBALS['catalog_verify_result'] = false;
    $unsigned = CatalogController::handle(new WP_REST_Request('GET'));
    expectSame(401, $unsigned->get_status(), 'unsigned status');
    expectSame(
        ['ok' => false, 'complete' => false, 'error' => 'unauthorized'],
        $unsigned->get_data(),
        'unsigned response'
    );
    expectSame(0, $GLOBALS['catalog_build_calls'], 'unsigned does not build');
    expectSecurityHeaders($unsigned, 'unsigned');

    resetCatalogTest();
    $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/uniple/v1/catalog';
    $alternatePath = CatalogController::handle(
        new WP_REST_Request('GET', signedHeaders())
    );
    expectSame(401, $alternatePath->get_status(), 'alternate path status');
    expectSame(0, count(UnipleClient::$verifyCalls), 'alternate path not verified');
    expectSame(0, $GLOBALS['catalog_build_calls'], 'alternate path does not build');

    resetCatalogTest();
    $_SERVER['REQUEST_URI'] = '/store/wp-json/uniple/v1/catalog?ignored=1';
    $success = CatalogController::handle(
        new WP_REST_Request('GET', signedHeaders())
    );
    expectSame(200, $success->get_status(), 'success status');
    expectTrue($success->get_data()['ok'] === true, 'success ok');
    expectTrue($success->get_data()['complete'] === true, 'success complete');
    expectSame(str_repeat('c', 64), $success->get_data()['revision'], 'success revision');
    expectSame(completeSnapshot()['products'], $success->get_data()['products'], 'success products');
    expectSame(1, count(UnipleClient::$verifyCalls), 'signature verified once');
    expectSame(
        [
            'version' => '1',
            'timestamp' => '2000000000',
            'nonce' => str_repeat('a', 32),
            'signature' => 'sha256='.str_repeat('b', 64),
            'method' => 'GET',
            'path' => '/store/wp-json/uniple/v1/catalog',
            'now' => null,
        ],
        UnipleClient::$verifyCalls[0],
        'external pathname signature contract'
    );
    expectSecurityHeaders($success, 'success');
    $successJson = (string) json_encode($success->get_data());
    expectSame(false, str_contains($successJson, 'api-key-must-not-leak'), 'API key absent');
    expectSame(false, str_contains($successJson, 'webhook-secret-must-not-leak'), 'webhook secret absent');
    expectSame(false, str_contains($successJson, 'sha256='), 'request signature absent');

    resetCatalogTest();
    $GLOBALS['catalog_snapshot']['truncated'] = 1;
    $truncated = CatalogController::handle(
        new WP_REST_Request('GET', signedHeaders())
    );
    expectSame(409, $truncated->get_status(), 'truncated status');
    expectSame(
        [
            'ok' => false,
            'complete' => false,
            'error' => 'catalog_too_large',
            'maxProducts' => 200,
        ],
        $truncated->get_data(),
        'truncated response'
    );
    expectSecurityHeaders($truncated, 'truncated');

    resetCatalogTest();
    $GLOBALS['catalog_snapshot']['products'] = array_fill(
        0,
        201,
        completeSnapshot()['products'][0]
    );
    $overLimit = CatalogController::handle(
        new WP_REST_Request('GET', signedHeaders())
    );
    expectSame(409, $overLimit->get_status(), 'defensive over-limit status');

    resetCatalogTest();
    $GLOBALS['catalog_build_throws'] = true;
    $failed = CatalogController::handle(
        new WP_REST_Request('GET', signedHeaders())
    );
    expectSame(500, $failed->get_status(), 'exception status');
    expectSame(
        ['ok' => false, 'complete' => false, 'error' => 'catalog_build_failed'],
        $failed->get_data(),
        'exception response'
    );
    expectSame(
        false,
        str_contains((string) json_encode($failed->get_data()), 'catalog-secret-must-not-leak'),
        'exception detail absent'
    );
    expectSecurityHeaders($failed, 'exception');

    resetCatalogTest();
    $GLOBALS['catalog_verify_throws'] = true;
    $verificationFailed = CatalogController::handle(
        new WP_REST_Request('GET', signedHeaders())
    );
    expectSame(500, $verificationFailed->get_status(), 'verification exception status');
    expectSame(
        false,
        str_contains(
            (string) json_encode($verificationFailed->get_data()),
            'verification-secret-must-not-leak'
        ),
        'verification exception detail absent'
    );
    expectSame(0, $GLOBALS['catalog_build_calls'], 'verification exception does not build');

    echo "catalog_controller_contract_ok\n";
}
