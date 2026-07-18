<?php
/*
 * uniple checkout for WooCommerce
 * Scheduled x402 product catalog sync entrypoint for WP-CLI.
 */

defined('ABSPATH') || exit;

use Uniple\CheckoutWooCommerce\Api\UnipleClient;
use Uniple\CheckoutWooCommerce\Gateway\UnipleGateway;
use Uniple\CheckoutWooCommerce\X402\ProductSync;

$unipleGateway = new UnipleGateway();
$unipleClient = new UnipleClient($unipleGateway->clientConfig());
$unipleResult = (new ProductSync())->syncAll($unipleClient);

update_option('uniple_x402_last_sync_message', sprintf(
    'x402商品同期を実行しました。同期: %d件 / 有効: %d件 / 無効: %d件 / 同期対象外: %d件 (%s) / 5分自動同期: %s',
    $unipleResult['synced'],
    $unipleResult['active'],
    $unipleResult['inactive'],
    $unipleResult['skipped'],
    current_time('mysql'),
    ($unipleResult['autoSync']['ok'] ?? false) === true
        && !empty($unipleResult['autoSync']['status']['enabled'])
        ? '登録済み'
        : '登録失敗'
), false);
if (($unipleResult['autoSync']['ok'] ?? false) === true
    && !empty($unipleResult['autoSync']['status']['enabled'])
) {
    delete_option('uniple_x402_last_sync_error');
} else {
    update_option(
        'uniple_x402_last_sync_error',
        '商品同期は成功しましたが、5分自動同期の登録に失敗しました。',
        false
    );
}

echo wp_json_encode(
    $unipleResult,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
).PHP_EOL;
