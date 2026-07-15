<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_REST_Response')) {
        final class WP_REST_Response
        {
            /** @param array<string,mixed> $data */
            /** @param array<string,string> $headers */
            public function __construct(
                private array $data,
                private int $status,
                private array $headers = []
            )
            {
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
    }

    if (!class_exists('WC_Order')) {
        final class WC_Order
        {
            /** @param array<string,string> $meta */
            public function __construct(
                private string $txHash,
                private bool $paid = true,
                private array $meta = [],
                private bool $throwOnSave = false
            )
            {
                $this->meta = array_merge([
                    '_uniple_x402_product_sku' => 'woocommerce-product-1',
                    '_uniple_x402_quote_id' => '',
                    '_uniple_x402_quantity' => '1',
                    '_uniple_x402_product_subtotal_jpyc' => '205',
                    '_uniple_x402_shipping_fee_jpyc' => '0',
                    '_uniple_x402_discount_jpyc' => '0',
                    '_uniple_x402_total_jpyc' => '205',
                ], $this->meta);
            }

            public function get_meta(string $key, bool $single = true): string
            {
                return $key === '_uniple_x402_tx_hash' ? $this->txHash : ($this->meta[$key] ?? '');
            }

            public function is_paid(): bool
            {
                return $this->paid;
            }

            public function get_id(): int
            {
                return 123;
            }

            public function get_total(): string
            {
                return $this->meta['_uniple_x402_total_jpyc'] ?? '0';
            }

            public function get_checkout_order_received_url(): string
            {
                return 'https://example.test/checkout/order-received/123/?key=wc_order_test';
            }

            public function set_payment_method(string $value): void {}
            public function set_payment_method_title(string $value): void {}
            public function set_address(array $value, string $type): void {}
            public function set_currency(string $value): void {}
            public function set_discount_total(float $value): void {}
            public function set_shipping_total(float $value): void {}
            public function add_product(WC_Product $product, int $quantity, array $args = []): void {}
            public function add_item(object $item): void {}
            public function add_order_note(string $note): void {}

            public function payment_complete(string $transactionId = ''): void
            {
                $this->paid = true;
            }

            public function set_total(float $value): void
            {
                $this->meta['_uniple_x402_total_jpyc'] = (string) (int) $value;
            }

            public function update_meta_data(string $key, mixed $value): void
            {
                $this->meta[$key] = (string) $value;
                if ($key === '_uniple_x402_tx_hash') {
                    $this->txHash = (string) $value;
                }
            }

            public function save(): int
            {
                if ($this->throwOnSave) {
                    throw new \RuntimeException('simulated_save_crash');
                }

                return 123;
            }
        }
    }

    if (!class_exists('WC_Product')) {
        final class WC_Product
        {
            public function get_id(): int
            {
                return 1;
            }

            public function get_name(): string
            {
                return 'Test product';
            }
        }
    }

    if (!class_exists('WC_Order_Item_Shipping')) {
        final class WC_Order_Item_Shipping
        {
            public function set_method_title(string $value): void {}
            public function set_method_id(string $value): void {}
            public function set_total(string $value): void {}
        }
    }

    if (!class_exists('UnipleTestWpdb')) {
        final class UnipleTestWpdb
        {
            /** @var array<int,string> */
            public array $queries = [];

            public function query(string $sql): int|false
            {
                $this->queries[] = $sql;

                return 0;
            }
        }
    }

    if (!function_exists('wc_get_orders')) {
        /** @return array<int,int> */
        function wc_get_orders(array $args): array
        {
            return isset($GLOBALS['uniple_test_x402_order']) ? [123] : [];
        }
    }

    if (!function_exists('wc_get_order')) {
        function wc_get_order(int $orderId): ?WC_Order
        {
            $order = $GLOBALS['uniple_test_x402_order'] ?? null;

            return $order instanceof WC_Order ? $order : null;
        }
    }

    if (!function_exists('wc_create_order')) {
        function wc_create_order(array $args = []): mixed
        {
            return $GLOBALS['uniple_test_created_order'] ?? null;
        }
    }

    if (!function_exists('wc_get_logger')) {
        function wc_get_logger(): object
        {
            return new class {
                public function error(string $message, array $context = []): void {}
                public function warning(string $message, array $context = []): void {}
            };
        }
    }

    if (!function_exists('__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }
}

namespace Uniple\CheckoutWooCommerce\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use Uniple\CheckoutWooCommerce\Api\UnipleClient;
    use Uniple\CheckoutWooCommerce\Webhook\WebhookController;
    use Uniple\CheckoutWooCommerce\X402\QuoteStore;

    require_once __DIR__.'/../../src/Webhook/WebhookController.php';

    final class WebhookX402DuplicateTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uniple_test_options'] = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['uniple_test_x402_order']);
            unset($GLOBALS['uniple_test_created_order'], $GLOBALS['wpdb']);
            $GLOBALS['uniple_test_options'] = [];
        }

        public function testExactSessionTransactionRetryReturnsDuplicateBeforeQuoteChecks(): void
        {
            $GLOBALS['uniple_test_x402_order'] = new \WC_Order('0x'.str_repeat('A', 64));

            $response = $this->existingResponse('checkout.session.completed:ucs_same', $this->tx('a'));

            self::assertInstanceOf(\WP_REST_Response::class, $response);
            self::assertSame(200, $response->get_status());
            self::assertSame(
                [
                    'ok' => true,
                    'orderId' => 123,
                    'completionUrl' => 'https://example.test/checkout/order-received/123/?key=wc_order_test',
                    'duplicate' => true,
                ],
                $response->get_data()
            );
        }

        public function testSameSessionWithDifferentTransactionReturnsConflict(): void
        {
            $GLOBALS['uniple_test_x402_order'] = new \WC_Order($this->tx('a'));

            $response = $this->existingResponse('checkout.session.completed:ucs_same', $this->tx('b'));

            self::assertInstanceOf(\WP_REST_Response::class, $response);
            self::assertSame(409, $response->get_status());
            self::assertSame(['error' => 'x402_idempotency_conflict'], $response->get_data());
        }

        public function testUnpaidDuplicateStaysRetryableInsteadOfAcknowledging202(): void
        {
            $GLOBALS['uniple_test_x402_order'] = new \WC_Order($this->tx('a'), false);

            $response = $this->existingResponse('checkout.session.completed:ucs_same', $this->tx('a'));

            self::assertInstanceOf(\WP_REST_Response::class, $response);
            self::assertSame(503, $response->get_status());
            self::assertSame('5', $response->get_headers()['Retry-After']);
            self::assertSame([
                'error' => 'x402_order_recovery_pending',
                'retryable' => true,
                'recoveryPending' => true,
                'orderId' => 123,
            ], $response->get_data());
        }

        public function testCreatedDuplicateRequiresExactProductAndFinancialPayload(): void
        {
            $GLOBALS['uniple_test_x402_order'] = new \WC_Order($this->tx('a'));

            $response = $this->existingResponse(
                'checkout.session.completed:ucs_same',
                $this->tx('a'),
                'woocommerce-product-2'
            );

            self::assertInstanceOf(\WP_REST_Response::class, $response);
            self::assertSame(409, $response->get_status());
            self::assertSame(['error' => 'x402_duplicate_payload_mismatch'], $response->get_data());
        }

        public function testSameSessionTransactionCannotClaimOrAttachASecondQuote(): void
        {
            $txHash = $this->tx('a');
            $GLOBALS['uniple_test_x402_order'] = new \WC_Order($txHash, true, [
                '_uniple_x402_product_sku' => 'woocommerce-product-1',
                '_uniple_x402_quote_id' => 'uq_quote_1',
                '_uniple_x402_quantity' => '1',
                '_uniple_x402_product_subtotal_jpyc' => '55',
                '_uniple_x402_shipping_fee_jpyc' => '150',
                '_uniple_x402_discount_jpyc' => '0',
                '_uniple_x402_total_jpyc' => '205',
                '_uniple_x402_quote_source' => 'woocommerce',
                '_uniple_x402_quote_expires_at' => '2027-01-15T00:00:00+00:00',
                '_uniple_x402_payload_hash' => hash('sha256', 'quote-1'),
            ]);
            $quote2 = [
                'quoteId' => 'uq_quote_2',
                'productSku' => 'woocommerce-product-1',
                'productId' => 1,
                'quantity' => 1,
                'productSubtotalJpyc' => '55',
                'shippingFeeJpyc' => '150',
                'discountJpyc' => '0',
                'totalJpyc' => '205',
                'expiresAt' => '2027-01-15T00:00:00+00:00',
                'usedAt' => null,
                'quoteSource' => 'woocommerce',
            ];
            QuoteStore::save($quote2);
            $data = [
                'sessionId' => 'ucs_same',
                'productSku' => 'woocommerce-product-1',
                'quoteId' => 'uq_quote_2',
                'quantity' => 1,
                'amountJpyc' => '205',
                'productSubtotalJpyc' => '55',
                'shippingFeeJpyc' => '150',
                'discountJpyc' => '0',
                'totalJpyc' => '205',
                'quoteSource' => 'woocommerce',
                'quoteExpiresAt' => '2027-01-15T00:00:00+00:00',
                'txHash' => $txHash,
            ];

            $method = new ReflectionMethod(WebhookController::class, 'handleX402Completed');
            $response = $method->invoke(
                null,
                $data,
                'checkout.session.completed',
                json_encode(['data' => $data]),
                new UnipleClient([
                    'api_key' => 'ums_test',
                    'webhook_secret' => 'whsec_test',
                    'merchant_label' => 'test',
                    'api_base_url' => 'https://uniple.io',
                    'mode' => 'test',
                ])
            );

            self::assertInstanceOf(\WP_REST_Response::class, $response);
            self::assertSame(409, $response->get_status());
            self::assertSame(['error' => 'x402_duplicate_payload_mismatch'], $response->get_data());
            self::assertNull(QuoteStore::findClaim('uq_quote_2'));
        }

        public function testExactCreatedDuplicateReturnsBeforeDeletedProductResolution(): void
        {
            $txHash = $this->tx('a');
            $quote = [
                'quoteId' => 'uq_quote_1',
                'productSku' => 'woocommerce-product-999999',
                'productId' => 999999,
                'quantity' => 1,
                'productSubtotalJpyc' => '55',
                'shippingFeeJpyc' => '150',
                'discountJpyc' => '0',
                'totalJpyc' => '205',
                'expiresAt' => '2027-01-15T00:00:00+00:00',
                'usedAt' => null,
                'quoteSource' => 'woocommerce',
            ];
            $data = [
                'sessionId' => 'ucs_same',
                'productSku' => 'woocommerce-product-999999',
                'quoteId' => 'uq_quote_1',
                'quantity' => 1,
                'amountJpyc' => '205',
                'productSubtotalJpyc' => '55',
                'shippingFeeJpyc' => '150',
                'discountJpyc' => '0',
                'totalJpyc' => '205',
                'quoteSource' => 'woocommerce',
                'quoteExpiresAt' => '2027-01-15T00:00:00+00:00',
                'txHash' => $txHash,
            ];
            QuoteStore::save($quote);
            $hashMethod = new ReflectionMethod(WebhookController::class, 'claimPayloadHash');
            $payloadHash = $hashMethod->invoke(
                null,
                $quote,
                $data,
                'woocommerce-product-999999',
                '205'
            );
            $GLOBALS['uniple_test_x402_order'] = new \WC_Order($txHash, true, [
                '_uniple_x402_product_sku' => 'woocommerce-product-999999',
                '_uniple_x402_quote_id' => 'uq_quote_1',
                '_uniple_x402_quantity' => '1',
                '_uniple_x402_product_subtotal_jpyc' => '55',
                '_uniple_x402_shipping_fee_jpyc' => '150',
                '_uniple_x402_discount_jpyc' => '0',
                '_uniple_x402_total_jpyc' => '205',
                '_uniple_x402_quote_source' => 'woocommerce',
                '_uniple_x402_quote_expires_at' => '2027-01-15T00:00:00+00:00',
                '_uniple_x402_payload_hash' => $payloadHash,
                '_uniple_x402_idempotency_key' => 'checkout.session.completed:ucs_same',
            ]);

            $method = new ReflectionMethod(WebhookController::class, 'handleX402Completed');
            $response = $method->invoke(
                null,
                $data,
                'checkout.session.completed',
                json_encode(['data' => $data]),
                new UnipleClient([
                    'api_key' => 'ums_test',
                    'webhook_secret' => 'whsec_test',
                    'merchant_label' => 'test',
                    'api_base_url' => 'https://uniple.io',
                    'mode' => 'test',
                ])
            );

            self::assertInstanceOf(\WP_REST_Response::class, $response);
            self::assertSame(200, $response->get_status());
            self::assertSame([
                'ok' => true,
                'orderId' => 123,
                'completionUrl' => 'https://example.test/checkout/order-received/123/?key=wc_order_test',
                'duplicate' => true,
                'recovered' => true,
            ], $response->get_data());
            self::assertSame(123, QuoteStore::find('uq_quote_1')['usedOrderId']);
        }

        public function testCommittedUnpaidSkeletonRecoversAfterProductDeletion(): void
        {
            $txHash = $this->tx('c');
            $quote = [
                'quoteId' => 'uq_deleted_recovery',
                'productSku' => 'woocommerce-product-deleted',
                'productId' => 999999,
                'quantity' => 2,
                'productSubtotalJpyc' => '100',
                'shippingFeeJpyc' => '50',
                'discountJpyc' => '0',
                'totalJpyc' => '150',
                'expiresAt' => '2027-01-15T00:00:00+00:00',
                'usedAt' => null,
                'quoteSource' => 'woocommerce',
            ];
            $data = [
                'sessionId' => 'ucs_deleted_recovery',
                'productSku' => 'woocommerce-product-deleted',
                'quoteId' => 'uq_deleted_recovery',
                'quantity' => 2,
                'amountJpyc' => '150',
                'productSubtotalJpyc' => '100',
                'shippingFeeJpyc' => '50',
                'discountJpyc' => '0',
                'totalJpyc' => '150',
                'quoteSource' => 'woocommerce',
                'quoteExpiresAt' => '2027-01-15T00:00:00+00:00',
                'txHash' => $txHash,
            ];
            QuoteStore::save($quote);
            $hashMethod = new ReflectionMethod(WebhookController::class, 'claimPayloadHash');
            $payloadHash = $hashMethod->invoke(null, $quote, $data, 'woocommerce-product-deleted', '150');
            $owner = [
                'idempotencyKey' => 'checkout.session.completed:ucs_deleted_recovery',
                'sessionId' => 'ucs_deleted_recovery',
                'txHash' => $txHash,
                'payloadHash' => $payloadHash,
            ];
            $claim = QuoteStore::claimUnused('uq_deleted_recovery', $owner)['claim'];
            self::assertIsArray($claim);
            self::assertTrue(QuoteStore::attachOrder('uq_deleted_recovery', (string) $claim['claimToken'], 123));
            $GLOBALS['uniple_test_x402_order'] = new \WC_Order($txHash, false, [
                '_uniple_x402_claim_token' => (string) $claim['claimToken'],
                '_uniple_x402_idempotency_key' => 'checkout.session.completed:ucs_deleted_recovery',
                '_uniple_x402_product_sku' => 'woocommerce-product-deleted',
                '_uniple_x402_quote_id' => 'uq_deleted_recovery',
                '_uniple_x402_quantity' => '2',
                '_uniple_x402_product_subtotal_jpyc' => '100',
                '_uniple_x402_shipping_fee_jpyc' => '50',
                '_uniple_x402_discount_jpyc' => '0',
                '_uniple_x402_total_jpyc' => '150',
                '_uniple_x402_quote_source' => 'woocommerce',
                '_uniple_x402_quote_expires_at' => '2027-01-15T00:00:00+00:00',
                '_uniple_x402_payload_hash' => $payloadHash,
            ]);

            $method = new ReflectionMethod(WebhookController::class, 'handleX402Completed');
            $response = $method->invoke(
                null,
                $data,
                'checkout.session.completed',
                json_encode(['data' => $data]),
                new UnipleClient([
                    'api_key' => 'ums_test',
                    'webhook_secret' => 'whsec_test',
                    'merchant_label' => 'test',
                    'api_base_url' => 'https://uniple.io',
                    'mode' => 'test',
                ])
            );

            self::assertSame(200, $response->get_status());
            self::assertTrue($GLOBALS['uniple_test_x402_order']->is_paid());
            self::assertSame(123, QuoteStore::find('uq_deleted_recovery')['usedOrderId']);
        }

        public function testFreshQuoteRequiresTheFullImmutableQ1Fields(): void
        {
            $quote = [
                'quoteId' => 'uq_full_q1',
                'productSku' => 'woocommerce-product-1',
                'productId' => 1,
                'quantity' => 1,
                'productSubtotalJpyc' => '55',
                'shippingFeeJpyc' => '150',
                'discountJpyc' => '0',
                'totalJpyc' => '205',
                'expiresAt' => '2027-01-15T00:00:00+00:00',
                'usedAt' => null,
                'quoteSource' => 'woocommerce',
            ];
            $data = [
                'quantity' => 1,
                'productSubtotalJpyc' => '55',
                'shippingFeeJpyc' => '150',
                'discountJpyc' => '0',
                'totalJpyc' => '205',
            ];
            $method = new ReflectionMethod(WebhookController::class, 'validateQuote');

            self::assertSame(
                'quote_source_missing',
                $method->invoke(null, $quote, $data, 'woocommerce-product-1', '205', new \WC_Product())
            );
            $data['quoteSource'] = 'woocommerce';
            self::assertSame(
                'quote_expires_at_missing',
                $method->invoke(null, $quote, $data, 'woocommerce-product-1', '205', new \WC_Product())
            );
        }

        public function testUnquotedCommittedSkeletonRecoversWithoutProductLookup(): void
        {
            $txHash = $this->tx('d');
            $data = [
                'sessionId' => 'legacy_deleted_recovery',
                'productSku' => 'woocommerce-product-deleted',
                'quantity' => 1,
                'amountJpyc' => '205',
                'productSubtotalJpyc' => '205',
                'shippingFeeJpyc' => '0',
                'discountJpyc' => '0',
                'totalJpyc' => '205',
                'txHash' => $txHash,
            ];
            $hashMethod = new ReflectionMethod(WebhookController::class, 'unquotedPayloadHash');
            $payloadHash = $hashMethod->invoke(null, $data, 'woocommerce-product-deleted', '205');
            $owner = [
                'idempotencyKey' => 'checkout.session.completed:legacy_deleted_recovery',
                'txHash' => $txHash,
                'productSku' => 'woocommerce-product-deleted',
                'amount' => '205',
                'payloadHash' => $payloadHash,
            ];
            $claim = QuoteStore::claimUnquoted($owner)['claim'];
            self::assertIsArray($claim);
            self::assertTrue(QuoteStore::attachUnquotedOrder(
                $owner['idempotencyKey'],
                (string) $claim['claimToken'],
                123
            ));
            $GLOBALS['uniple_test_x402_order'] = new \WC_Order($txHash, false, [
                '_uniple_x402_claim_token' => (string) $claim['claimToken'],
                '_uniple_x402_payload_hash' => $payloadHash,
                '_uniple_x402_idempotency_key' => $owner['idempotencyKey'],
                '_uniple_x402_product_sku' => 'woocommerce-product-deleted',
                '_uniple_x402_quote_id' => '',
                '_uniple_x402_quantity' => '1',
                '_uniple_x402_product_subtotal_jpyc' => '205',
                '_uniple_x402_shipping_fee_jpyc' => '0',
                '_uniple_x402_discount_jpyc' => '0',
                '_uniple_x402_total_jpyc' => '205',
            ]);

            $method = new ReflectionMethod(WebhookController::class, 'handleX402Completed');
            $response = $method->invoke(
                null,
                $data,
                'checkout.session.completed',
                json_encode(['data' => $data]),
                new UnipleClient([
                    'api_key' => 'ums_test',
                    'webhook_secret' => 'whsec_test',
                    'merchant_label' => 'test',
                    'api_base_url' => 'https://uniple.io',
                    'mode' => 'test',
                ])
            );

            self::assertSame(200, $response->get_status());
            self::assertTrue($GLOBALS['uniple_test_x402_order']->is_paid());
            self::assertSame('used', QuoteStore::findUnquotedClaim($owner['idempotencyKey'])['state']);
        }

        public function testRolledBackSkeletonCanRetryAndAtomicallyAttachOrder(): void
        {
            $quote = [
                'quoteId' => 'uq_atomic',
                'productSku' => 'woocommerce-product-1',
                'productId' => 1,
                'quantity' => 1,
                'productSubtotalJpyc' => '55',
                'shippingFeeJpyc' => '150',
                'discountJpyc' => '0',
                'totalJpyc' => '205',
                'expiresAt' => '2027-01-15T00:00:00+00:00',
                'usedAt' => null,
                'quoteSource' => 'woocommerce',
            ];
            QuoteStore::save($quote);
            $owner = [
                'idempotencyKey' => 'checkout.session.completed:ucs_atomic',
                'sessionId' => 'ucs_atomic',
                'txHash' => $this->tx('a'),
                'payloadHash' => hash('sha256', 'atomic-q1'),
            ];
            $claim = QuoteStore::claimUnused('uq_atomic', $owner)['claim'];
            self::assertIsArray($claim);
            $method = new ReflectionMethod(WebhookController::class, 'createAndAttachClaimedOrderSkeleton');
            $args = [
                $claim,
                $quote,
                new \WC_Product(),
                ['country' => 'JP'],
                'woocommerce-product-1',
                1,
                '55',
                '150',
                '0',
                '205',
                'checkout.session.completed:ucs_atomic',
                'merchant-1',
                'client-1',
                $this->tx('a'),
                '0xpayer',
                'Test product',
            ];

            $GLOBALS['wpdb'] = new \UnipleTestWpdb();
            $GLOBALS['uniple_test_created_order'] = new \WC_Order($this->tx('a'), false, [], true);
            self::assertNull($method->invokeArgs(null, $args));
            self::assertSame(['START TRANSACTION', 'ROLLBACK'], $GLOBALS['wpdb']->queries);
            self::assertSame(0, QuoteStore::findClaim('uq_atomic')['orderId']);

            $GLOBALS['wpdb'] = new \UnipleTestWpdb();
            $GLOBALS['uniple_test_created_order'] = new \WC_Order($this->tx('a'), false);
            $created = $method->invokeArgs(null, $args);
            self::assertInstanceOf(\WC_Order::class, $created);
            self::assertSame(['START TRANSACTION', 'COMMIT'], $GLOBALS['wpdb']->queries);
            self::assertSame(123, QuoteStore::findClaim('uq_atomic')['orderId']);
        }

        public function testExpiredLockOwnerCannotDeleteTakeoverLock(): void
        {
            $acquire = new ReflectionMethod(WebhookController::class, 'acquireLockKey');
            $release = new ReflectionMethod(WebhookController::class, 'releaseLockKey');
            $keyMethod = new ReflectionMethod(WebhookController::class, 'lockOptionKey');
            $suffix = 'x402_quote_lock_test';
            $key = $keyMethod->invoke(null, $suffix);

            $firstToken = $acquire->invoke(null, $suffix);
            self::assertIsString($firstToken);
            $GLOBALS['uniple_test_options'][$key]['acquiredAt'] = time() - 61;

            $secondToken = $acquire->invoke(null, $suffix);
            self::assertIsString($secondToken);
            self::assertNotSame($firstToken, $secondToken);

            $release->invoke(null, $suffix, $firstToken);
            self::assertSame($secondToken, $GLOBALS['uniple_test_options'][$key]['token']);
            self::assertNull($acquire->invoke(null, $suffix));

            $release->invoke(null, $suffix, $secondToken);
            self::assertIsString($acquire->invoke(null, $suffix));
        }

        private function existingResponse(
            string $idempotencyKey,
            string $txHash,
            string $productSku = 'woocommerce-product-1'
        ): ?\WP_REST_Response
        {
            $method = new ReflectionMethod(WebhookController::class, 'existingX402OrderResponse');

            /** @var \WP_REST_Response|null $response */
            $response = $method->invoke(
                null,
                $idempotencyKey,
                $txHash,
                $productSku,
                '205',
                ['quantity' => 1]
            );

            return $response;
        }

        private function tx(string $hex): string
        {
            return '0x'.str_repeat($hex, 64);
        }
    }
}
