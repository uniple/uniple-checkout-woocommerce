<?php
/*
 * uniple checkout for WooCommerce
 * Scheduled x402 product catalog sync entrypoint for WP-CLI.
 */

use Uniple\CheckoutWooCommerce\Api\UnipleClient;
use Uniple\CheckoutWooCommerce\Gateway\UnipleGateway;
use Uniple\CheckoutWooCommerce\X402\ProductSync;

$gateway = new UnipleGateway();
$client = new UnipleClient($gateway->clientConfig());
$result = (new ProductSync())->syncAll($client);

update_option('uniple_x402_last_sync_message', sprintf(
    'x402商品同期を実行しました。同期: %d件 / 有効: %d件 / 無効: %d件 / 同期対象外: %d件 (%s) / 5分自動同期: %s',
    $result['synced'],
    $result['active'],
    $result['inactive'],
    $result['skipped'],
    current_time('mysql'),
    ($result['autoSync']['ok'] ?? false) === true
        && !empty($result['autoSync']['status']['enabled'])
        ? '登録済み'
        : '登録失敗'
), false);
if (($result['autoSync']['ok'] ?? false) === true
    && !empty($result['autoSync']['status']['enabled'])
) {
    delete_option('uniple_x402_last_sync_error');
} else {
    update_option(
        'uniple_x402_last_sync_error',
        '商品同期は成功しましたが、5分自動同期の登録に失敗しました。',
        false
    );
}

echo wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
