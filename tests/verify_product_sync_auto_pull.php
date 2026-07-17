<?php

declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__);

    /** @var array<int,WC_Product> */
    $GLOBALS['catalog_products'] = [];
    /** @var array<int,int> */
    $GLOBALS['catalog_product_ids'] = [];
    /** @var array<int,array<string,string>> */
    $GLOBALS['catalog_product_meta'] = [];
    /** @var array<int,array<string,mixed>> */
    $GLOBALS['catalog_queries'] = [];
    $GLOBALS['catalog_rest_base'] = 'https://shop.example/wp-json/';

    class WC_Product
    {
        public function __construct(
            private int $id,
            private string $name,
            private string $price,
            private string $status = 'publish',
            private bool $purchasable = true,
            private bool $inStock = true,
            private string $description = '',
            private string $shortDescription = '',
            private int $imageId = 0
        ) {
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_price(): string
        {
            return $this->price;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function is_purchasable(): bool
        {
            return $this->purchasable;
        }

        public function is_in_stock(): bool
        {
            return $this->inStock;
        }

        public function get_description(): string
        {
            return $this->description;
        }

        public function get_short_description(): string
        {
            return $this->shortDescription;
        }

        public function get_image_id(): int
        {
            return $this->imageId;
        }
    }

    class WC_Product_Variable extends WC_Product
    {
        /**
         * @param array<int,int> $children
         */
        public function __construct(
            int $id,
            string $name,
            private array $children,
            string $status = 'publish',
            string $description = '',
            int $imageId = 0
        ) {
            parent::__construct(
                $id,
                $name,
                '',
                $status,
                false,
                false,
                $description,
                '',
                $imageId
            );
        }

        /**
         * @return array<int,int>
         */
        public function get_children(): array
        {
            return $this->children;
        }
    }

    class WC_Product_Variation extends WC_Product
    {
    }

    /**
     * @param array<string,mixed> $args
     * @return array<int,int>
     */
    function wc_get_products(array $args): array
    {
        $GLOBALS['catalog_queries'][] = $args;

        return $GLOBALS['catalog_product_ids'];
    }

    function wc_get_product(int $id): ?WC_Product
    {
        return $GLOBALS['catalog_products'][$id] ?? null;
    }

    /**
     * @param array<string,mixed> $args
     */
    function wc_get_price_including_tax(WC_Product $product, array $args = []): string
    {
        return $product->get_price();
    }

    function get_post_meta(int $postId, string $key, bool $single = false): string
    {
        return $GLOBALS['catalog_product_meta'][$postId][$key] ?? '';
    }

    function get_permalink(int $postId): string
    {
        return 'https://shop.example/product/'.$postId;
    }

    function wp_strip_all_tags(string $text): string
    {
        return strip_tags($text);
    }

    function wp_get_attachment_image_url(int $imageId, string $size): string
    {
        return 'https://shop.example/media/'.$imageId.'.jpg';
    }

    if (!function_exists('mb_substr')) {
        function mb_substr(
            string $value,
            int $offset,
            ?int $length = null
        ): string {
            return $length === null
                ? substr($value, $offset)
                : substr($value, $offset, $length);
        }
    }

    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }

    function rest_url(string $path = ''): string
    {
        return $GLOBALS['catalog_rest_base'].ltrim($path, '/');
    }

    function wp_parse_url(string $url): array|false
    {
        return parse_url($url);
    }
}

namespace Uniple\CheckoutWooCommerce\Api {
    use RuntimeException;

    final class UnipleClient
    {
        /** @var array<int,string> */
        public array $events = [];
        /** @var array<int,array<string,mixed>> */
        public array $syncCalls = [];
        /** @var array<int,string> */
        public array $registrationCalls = [];
        public bool $pushThrows = false;
        public bool $registrationThrows = false;
        public bool $statusThrows = false;

        /**
         * @param array<int,array<string,mixed>> $products
         * @return array<string,mixed>
         */
        public function syncProducts(array $products, bool $replace = false, string $scope = ''): array
        {
            $this->events[] = 'push';
            $this->syncCalls[] = [
                'products' => $products,
                'replace' => $replace,
                'scope' => $scope,
            ];
            if ($this->pushThrows) {
                throw new RuntimeException('push_unavailable');
            }

            return ['ok' => true, 'httpStatus' => 200];
        }

        /**
         * @return array<string,mixed>
         */
        public function registerCatalogSync(string $endpointUrl): array
        {
            $this->events[] = 'register';
            $this->registrationCalls[] = $endpointUrl;
            if ($this->registrationThrows) {
                throw new RuntimeException('registration_unavailable');
            }

            return [
                'ok' => true,
                'status' => ['enabled' => true],
            ];
        }

        /**
         * @return array<string,mixed>
         */
        public function getCatalogSyncStatus(): array
        {
            $this->events[] = 'status';
            if ($this->statusThrows) {
                throw new RuntimeException('status_unavailable');
            }

            return [
                'ok' => true,
                'status' => ['enabled' => true],
            ];
        }

        /**
         * @return array<string,mixed>
         */
        public function deleteCatalogSync(): array
        {
            $this->events[] = 'delete';

            return [
                'ok' => true,
                'status' => ['enabled' => false],
            ];
        }
    }
}

namespace {
    use Uniple\CheckoutWooCommerce\Api\UnipleClient;
    use Uniple\CheckoutWooCommerce\X402\ProductSync;

    require_once dirname(__DIR__).'/src/X402/ProductSync.php';

    function expectSame(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected === $actual) {
            return;
        }

        fwrite(
            STDERR,
            'FAIL: '.$label."\nexpected: ".var_export($expected, true)
            ."\nactual: ".var_export($actual, true)."\n"
        );
        exit(1);
    }

    function expectThrows(string $expectedMessage, callable $operation, string $label): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            expectSame($expectedMessage, $e->getMessage(), $label);

            return;
        }

        fwrite(STDERR, 'FAIL: '.$label." did not throw\n");
        exit(1);
    }

    function resetCatalogFixtures(): void
    {
        $GLOBALS['catalog_products'] = [];
        $GLOBALS['catalog_product_ids'] = [];
        $GLOBALS['catalog_product_meta'] = [];
        $GLOBALS['catalog_queries'] = [];
        $GLOBALS['catalog_rest_base'] = 'https://shop.example/wp-json/';
    }

    function addCatalogProduct(WC_Product $product, bool $root = true): void
    {
        $GLOBALS['catalog_products'][$product->get_id()] = $product;
        if ($root) {
            $GLOBALS['catalog_product_ids'][] = $product->get_id();
        }
    }

    resetCatalogFixtures();
    addCatalogProduct(new WC_Product(
        1,
        'Alpha',
        '10.50',
        'publish',
        true,
        true,
        "<b>Alpha</b>\n details",
        '',
        11
    ));
    addCatalogProduct(new WC_Product(2, 'Beta', '20'));
    $GLOBALS['catalog_product_meta'][2]['_uniple_x402_ai_enabled'] = 'no';
    addCatalogProduct(new WC_Product(3, 'No price', ''));
    addCatalogProduct(new WC_Product_Variable(
        4,
        'Shirt',
        [41],
        'publish',
        '<p>Parent description</p>',
        44
    ));
    addCatalogProduct(new WC_Product_Variation(41, 'Blue', '30'), false);
    addCatalogProduct(new WC_Product(5, 'Draft', '40', 'draft'));

    $sync = new ProductSync();
    $catalog = $sync->buildCatalog();
    expectSame(4, count($catalog['products']), 'complete catalog product count');
    expectSame(2, $catalog['active'], 'active catalog count');
    expectSame(2, $catalog['inactive'], 'inactive catalog count');
    expectSame(1, $catalog['skipped'], 'invalid product count');
    expectSame(0, $catalog['truncated'], 'complete catalog is not truncated');
    expectSame(
        [
            'externalId' => 'woocommerce-product-1',
            'name' => 'Alpha',
            'priceJpyc' => '10.5',
            'active' => true,
            'description' => 'Alpha details',
            'imageUrl' => 'https://shop.example/media/11.jpg',
            'pageUrl' => 'https://shop.example/product/1',
            'taxLabel' => '税込',
            'sortOrder' => 0,
        ],
        $catalog['products'][0],
        'existing simple-product payload remains stable'
    );
    expectSame(false, $catalog['products'][1]['active'], 'AI-disabled product is inactive');
    expectSame(
        'woocommerce-product-4-variation-41',
        $catalog['products'][2]['externalId'],
        'variation external ID remains stable'
    );
    expectSame('Shirt / Blue', $catalog['products'][2]['name'], 'variation name remains stable');
    expectSame(
        'Parent description',
        $catalog['products'][2]['description'],
        'variation inherits parent description'
    );
    expectSame(
        'https://shop.example/media/44.jpg',
        $catalog['products'][2]['imageUrl'],
        'variation inherits parent image'
    );
    expectSame(
        'https://shop.example/product/4',
        $catalog['products'][2]['pageUrl'],
        'variation links to parent'
    );
    expectSame(3, $catalog['products'][2]['sortOrder'], 'invalid product keeps existing sort gap');
    expectSame(false, $catalog['products'][3]['active'], 'draft product is inactive');
    expectSame(
        hash(
            'sha256',
            json_encode(
                $catalog['products'],
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
            )
        ),
        $catalog['revision'],
        'catalog revision hashes the deterministic snapshot'
    );
    expectSame($catalog['revision'], $sync->buildCatalog()['revision'], 'catalog revision is repeatable');
    expectSame(-1, $GLOBALS['catalog_queries'][0]['limit'], 'catalog reads the complete source set');
    expectSame(
        'https://shop.example/wp-json/uniple/v1/catalog',
        $sync->catalogEndpointUrl(),
        'public catalog endpoint'
    );
    $GLOBALS['catalog_rest_base'] = 'https://shop.example/?rest_route=/';
    expectThrows(
        'catalog_pretty_permalink_required',
        static function () use ($sync): void {
            $sync->catalogEndpointUrl();
        },
        'query-form REST endpoint fails before registration'
    );
    $GLOBALS['catalog_rest_base'] = 'https://shop.example/wp-json/';

    $client = new UnipleClient();
    $result = $sync->syncAll($client);
    expectSame(['push', 'register'], $client->events, 'registration follows successful push');
    expectSame(true, $client->syncCalls[0]['replace'], 'manual push replaces the snapshot');
    expectSame('site', $client->syncCalls[0]['scope'], 'manual push uses site scope');
    expectSame(
        'https://shop.example/wp-json/uniple/v1/catalog',
        $client->registrationCalls[0],
        'registration uses the public endpoint'
    );
    expectSame(4, $result['synced'], 'sync result count');
    expectSame(true, $result['autoSync']['status']['enabled'], 'auto sync registration result');

    $pushFailureClient = new UnipleClient();
    $pushFailureClient->pushThrows = true;
    expectThrows(
        'push_unavailable',
        static function () use ($sync, $pushFailureClient): void {
            $sync->syncAll($pushFailureClient);
        },
        'failed push is surfaced'
    );
    expectSame(['push'], $pushFailureClient->events, 'failed push never registers');

    $registrationFailureClient = new UnipleClient();
    $registrationFailureClient->registrationThrows = true;
    $registrationFailure = $sync->syncAll($registrationFailureClient);
    expectSame(
        ['push', 'register'],
        $registrationFailureClient->events,
        'registration failure occurs after successful push'
    );
    expectSame(false, $registrationFailure['autoSync']['ok'], 'registration failure is separated');
    expectSame(
        'uniple_catalog_sync_registration_failed',
        $registrationFailure['autoSync']['error'],
        'registration failure is returned as a fixed public code'
    );
    expectSame(true, $registrationFailure['response']['ok'], 'successful push result is preserved');

    $statusClient = new UnipleClient();
    expectSame(
        true,
        $sync->getAutoSyncStatus($statusClient)['status']['enabled'],
        'auto sync status helper'
    );
    expectSame(
        false,
        $sync->deleteAutoSync($statusClient)['status']['enabled'],
        'auto sync delete helper'
    );
    $statusFailureClient = new UnipleClient();
    $statusFailureClient->statusThrows = true;
    expectSame(
        [
            'ok' => false,
            'error' => 'uniple_catalog_sync_status_failed',
        ],
        $sync->getAutoSyncStatus($statusFailureClient),
        'status failure is returned as a fixed public code'
    );

    resetCatalogFixtures();
    for ($id = 1; $id <= 201; ++$id) {
        addCatalogProduct(new WC_Product($id, 'Product '.$id, '10'));
    }
    $tooManySync = new ProductSync();
    $tooManyCatalog = $tooManySync->buildCatalog();
    expectSame(200, count($tooManyCatalog['products']), 'catalog hard limit');
    expectSame(1, $tooManyCatalog['truncated'], 'catalog reports source overflow');
    $tooManyClient = new UnipleClient();
    expectThrows(
        'catalog_too_large_max_200',
        static function () use ($tooManySync, $tooManyClient): void {
            $tooManySync->syncAll($tooManyClient);
        },
        'oversized replacement fails closed'
    );
    expectSame([], $tooManyClient->events, 'partial catalog is neither pushed nor registered');

    echo "OK: WooCommerce product sync auto-pull contract\n";
}
