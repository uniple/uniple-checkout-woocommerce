<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Error')) {
        final class WP_Error
        {
            public function __construct(private readonly string $message)
            {
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    if (!function_exists('wp_json_encode')) {
        function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
        {
            return json_encode($value, $flags, $depth);
        }
    }

    if (!function_exists('wp_remote_request')) {
        /**
         * @param array<string,mixed> $args
         *
         * @return array<string,mixed>|\WP_Error
         */
        function wp_remote_request(string $url, array $args = []): array|\WP_Error
        {
            $GLOBALS['uniple_catalog_http_requests'][] = [
                'url' => $url,
                'args' => $args,
            ];
            $response = array_shift($GLOBALS['uniple_catalog_http_responses']);

            return $response instanceof \WP_Error ? $response : $response;
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error(mixed $value): bool
        {
            return $value instanceof \WP_Error;
        }
    }

    if (!function_exists('wp_remote_retrieve_response_code')) {
        /**
         * @param array<string,mixed> $response
         */
        function wp_remote_retrieve_response_code(array $response): int
        {
            return (int) ($response['response']['code'] ?? 0);
        }
    }

    if (!function_exists('wp_remote_retrieve_body')) {
        /**
         * @param array<string,mixed> $response
         */
        function wp_remote_retrieve_body(array $response): string
        {
            return (string) ($response['body'] ?? '');
        }
    }
}

namespace Uniple\CheckoutWooCommerce\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use Uniple\CheckoutWooCommerce\Api\UnipleClient;
    use Uniple\CheckoutWooCommerce\Plugin;

    final class UnipleClientCatalogProtocolTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uniple_catalog_http_requests'] = [];
            $GLOBALS['uniple_catalog_http_responses'] = [];
        }

        public function testCatalogSignatureProtocolMatchesCanonicalContract(): void
        {
            $client = $this->makeClient();
            $timestamp = '2000000000';
            $nonce = '0123456789abcdef0123456789abcdef';
            $path = '/wp-json/uniple/v1/catalog';
            $apiKey = 'sk_catalog_test';
            $derivedSecret = hash_hmac(
                'sha256',
                'uniple-catalog-pull-v1',
                $apiKey,
                true
            );
            $canonical = implode("\n", [
                'UNIPLE-CATALOG-PULL-V1',
                $timestamp,
                $nonce,
                'GET',
                $path,
            ]);
            $expectedSignature = 'sha256='.hash_hmac('sha256', $canonical, $derivedSecret);

            self::assertSame('1', UnipleClient::CATALOG_VERSION);
            self::assertSame(300, UnipleClient::CATALOG_SYNC_INTERVAL_SECONDS);
            self::assertSame(300, UnipleClient::CATALOG_MAX_CLOCK_SKEW_SECONDS);
            self::assertSame($derivedSecret, UnipleClient::deriveCatalogPullSecret($apiKey));
            self::assertSame(
                $expectedSignature,
                UnipleClient::buildCatalogSignature($timestamp, $nonce, 'get', $path, $apiKey)
            );
            self::assertTrue(
                $client->verifyCatalogRequestSignature(
                    '1',
                    $timestamp,
                    $nonce,
                    $expectedSignature,
                    'GET',
                    $path,
                    2000000000
                )
            );
        }

        public function testCatalogSignatureVerificationRejectsInvalidInputs(): void
        {
            $client = $this->makeClient();
            $timestamp = '2000000000';
            $nonce = '0123456789abcdef0123456789abcdef';
            $path = '/wp-json/uniple/v1/catalog';
            $signature = UnipleClient::buildCatalogSignature(
                $timestamp,
                $nonce,
                'GET',
                $path,
                'sk_catalog_test'
            );

            self::assertFalse($client->verifyCatalogRequestSignature('2', $timestamp, $nonce, $signature, 'GET', $path, 2000000000));
            self::assertFalse($client->verifyCatalogRequestSignature('1', '1999999699', $nonce, $signature, 'GET', $path, 2000000000));
            self::assertFalse($client->verifyCatalogRequestSignature('1', $timestamp, strtoupper($nonce), $signature, 'GET', $path, 2000000000));
            self::assertFalse($client->verifyCatalogRequestSignature('1', $timestamp, $nonce, $signature, 'POST', $path, 2000000000));
            self::assertFalse($client->verifyCatalogRequestSignature('1', $timestamp, $nonce, $signature, 'GET', 'wp-json/uniple/v1/catalog', 2000000000));
            self::assertFalse($client->verifyCatalogRequestSignature('1', $timestamp, $nonce, $signature, 'GET', $path."\nignored", 2000000000));
            self::assertFalse($client->verifyCatalogRequestSignature('1', $timestamp, $nonce, 'sha256='.str_repeat('0', 64), 'GET', $path, 2000000000));
            self::assertFalse(
                $this->makeClient('')
                    ->verifyCatalogRequestSignature('1', $timestamp, $nonce, $signature, 'GET', $path, 2000000000)
            );
        }

        public function testRegisterCatalogSyncUsesPurposeDerivedSecretAndWooCommerceMetadata(): void
        {
            $this->queueResponse(200, [
                'ok' => true,
                'status' => ['enabled' => true],
            ]);
            $endpointUrl = 'https://wp.uniple.io/wp-json/uniple/v1/catalog';

            $result = $this->makeClient()->registerCatalogSync($endpointUrl);

            self::assertSame(200, $result['httpStatus']);
            $request = $GLOBALS['uniple_catalog_http_requests'][0];
            self::assertSame('https://dev.uniple.io/api/merchant/catalog-sync', $request['url']);
            self::assertSame('PUT', $request['args']['method']);
            self::assertSame('Bearer sk_catalog_test', $request['args']['headers']['Authorization']);
            self::assertSame('application/json', $request['args']['headers']['Accept']);
            self::assertSame('application/json', $request['args']['headers']['Content-Type']);
            self::assertSame(UnipleClient::TIMEOUT_SECONDS, $request['args']['timeout']);

            $body = json_decode((string) $request['args']['body'], true, 512, JSON_THROW_ON_ERROR);
            $expectedPullSecret = rtrim(
                strtr(
                    base64_encode(UnipleClient::deriveCatalogPullSecret('sk_catalog_test')),
                    '+/',
                    '-_'
                ),
                '='
            );
            self::assertSame($endpointUrl, $body['endpointUrl']);
            self::assertSame($expectedPullSecret, $body['pullSecret']);
            self::assertSame(43, strlen($body['pullSecret']));
            self::assertSame('woocommerce', $body['platform']);
            self::assertSame(Plugin::VERSION, $body['pluginVersion']);
            self::assertSame(300, $body['intervalSeconds']);
        }

        public function testGetAndDeleteCatalogSyncUseBodylessRequests(): void
        {
            $this->queueResponse(200, ['ok' => true, 'status' => ['enabled' => true]]);
            $this->queueResponse(200, ['ok' => true, 'status' => ['enabled' => false]]);
            $client = $this->makeClient();

            $status = $client->getCatalogSyncStatus();
            $deleted = $client->deleteCatalogSync();

            self::assertTrue($status['status']['enabled']);
            self::assertFalse($deleted['status']['enabled']);
            self::assertSame('GET', $GLOBALS['uniple_catalog_http_requests'][0]['args']['method']);
            self::assertArrayNotHasKey('body', $GLOBALS['uniple_catalog_http_requests'][0]['args']);
            self::assertArrayNotHasKey('Content-Type', $GLOBALS['uniple_catalog_http_requests'][0]['args']['headers']);
            self::assertSame('DELETE', $GLOBALS['uniple_catalog_http_requests'][1]['args']['method']);
            self::assertArrayNotHasKey('body', $GLOBALS['uniple_catalog_http_requests'][1]['args']);
        }

        public function testCatalogSyncRejectsTransportFailure(): void
        {
            $GLOBALS['uniple_catalog_http_responses'][] = new \WP_Error('network unavailable');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('uniple_catalog_sync_status_failed: transport_error');
            $this->makeClient()->getCatalogSyncStatus();
        }

        public function testCatalogSyncRejectsNonSuccessHttpStatus(): void
        {
            $this->queueResponse(401, ['ok' => true]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('uniple_catalog_sync_status_failed: status=401');
            $this->makeClient()->getCatalogSyncStatus();
        }

        public function testCatalogSyncRejectsMalformedJson(): void
        {
            $GLOBALS['uniple_catalog_http_responses'][] = [
                'response' => ['code' => 200],
                'body' => '{',
            ];

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('uniple_catalog_sync_status_failed: invalid_json');
            $this->makeClient()->getCatalogSyncStatus();
        }

        public function testCatalogSyncRequiresStrictTrueOk(): void
        {
            $this->queueResponse(200, ['ok' => 1]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('uniple_catalog_sync_status_failed: invalid_payload');
            $this->makeClient()->getCatalogSyncStatus();
        }

        public function testCatalogSyncRejectsMissingApiKeyBeforeHttpRequest(): void
        {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('uniple_api_key_not_configured');

            try {
                $this->makeClient('')->getCatalogSyncStatus();
            } finally {
                self::assertSame([], $GLOBALS['uniple_catalog_http_requests']);
            }
        }

        /**
         * @param array<string,mixed> $payload
         */
        private function queueResponse(int $status, array $payload): void
        {
            $GLOBALS['uniple_catalog_http_responses'][] = [
                'response' => ['code' => $status],
                'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            ];
        }

        private function makeClient(string $apiKey = 'sk_catalog_test'): UnipleClient
        {
            return new UnipleClient([
                'api_key' => $apiKey,
                'webhook_secret' => 'whsec_test',
                'merchant_label' => 'demo',
                'api_base_url' => 'https://dev.uniple.io',
                'mode' => 'test',
            ]);
        }
    }
}
