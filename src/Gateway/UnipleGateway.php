<?php
/*
 * uniple checkout for WooCommerce
 * Copyright (C) 2026 uniple inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Gateway;

use Uniple\CheckoutWooCommerce\Admin\SettingsSanitizer;
use Uniple\CheckoutWooCommerce\Api\UnipleClient;
use Uniple\CheckoutWooCommerce\Plugin;
use Uniple\CheckoutWooCommerce\X402\ProductSync;
use WC_Order;
use WC_Payment_Gateway;

defined('ABSPATH') || exit;

/**
 * WC_Payment_Gateway 継承の uniple checkout gateway。
 *
 * process_payment で uniple Merchant API POST sessions → 返却 checkoutUrl を
 * `result=success, redirect=<checkoutUrl>` で返却 (= WC 標準 redirect 経路)。
 * MM modal warmup + auto retry (= ?wc3=1 default) は uniple SSR 側で完結、
 * plugin は thin client (= r22 設計)。
 */
final class UnipleGateway extends WC_Payment_Gateway
{
    private const X402_LAST_SYNC_MESSAGE_OPTION = 'uniple_x402_last_sync_message';
    private const X402_LAST_SYNC_ERROR_OPTION = 'uniple_x402_last_sync_error';

    public function __construct()
    {
        $this->id = Plugin::PLUGIN_ID;
        $this->method_title = __('uniple checkout (JPYC)', 'uniple-checkout-for-woocommerce');
        $this->method_description = __(
            'JPYC stablecoin hosted checkout, powered by uniple. Redirects to uniple checkout page.',
            'uniple-checkout-for-woocommerce'
        );
        $this->has_fields = false;
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = (string) $this->get_option('title', __('uniple checkout (JPYC)', 'uniple-checkout-for-woocommerce'));
        $this->description = (string) $this->get_option(
            'description',
            __('Pay with JPYC via uniple hosted checkout.', 'uniple-checkout-for-woocommerce')
        );

        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable / Disable', 'uniple-checkout-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable uniple checkout (JPYC)', 'uniple-checkout-for-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'uniple-checkout-for-woocommerce'),
                'type' => 'text',
                'default' => __('uniple checkout (JPYC)', 'uniple-checkout-for-woocommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'uniple-checkout-for-woocommerce'),
                'type' => 'textarea',
                'default' => __('Pay with JPYC via uniple hosted checkout.', 'uniple-checkout-for-woocommerce'),
            ],
            'api_base_url' => [
                'title' => __('API base URL', 'uniple-checkout-for-woocommerce'),
                'type' => 'text',
                'default' => 'https://uniple.io',
                'desc_tip' => true,
            ],
            'mode' => [
                'title' => __('Mode', 'uniple-checkout-for-woocommerce'),
                'type' => 'select',
                'options' => [
                    'live' => __('Live', 'uniple-checkout-for-woocommerce'),
                    'test' => __('Test', 'uniple-checkout-for-woocommerce'),
                ],
                'default' => 'live',
            ],
            'merchant_label' => [
                'title' => __('Merchant label', 'uniple-checkout-for-woocommerce'),
                'type' => 'text',
                'default' => '',
            ],
            'api_key' => [
                'title' => __('API key', 'uniple-checkout-for-woocommerce'),
                'type' => 'password',
                'default' => '',
                'description' => __('Issued by uniple admin. Stored masked; re-enter to update.', 'uniple-checkout-for-woocommerce'),
            ],
            'webhook_secret' => [
                'title' => __('Webhook secret', 'uniple-checkout-for-woocommerce'),
                'type' => 'password',
                'default' => '',
                'description' => __('HMAC-SHA256 webhook signing secret. Stored masked; re-enter to update.', 'uniple-checkout-for-woocommerce'),
            ],
            'x402_sync' => [
                'title' => __('x402 / AI購入 商品同期', 'uniple-checkout-for-woocommerce'),
                'type' => 'x402_sync',
            ],
        ];
    }

    public function admin_options()
    {
        $apiKey = (string) $this->get_option('api_key', '');
        if ($apiKey === '') {
            echo '<div class="notice notice-info inline"><p>';
            echo wp_kses_post(
                sprintf(
                    /* translators: %s: merchant application form URL */
                    __(
                        'uniple checkout requires a merchant account. <a href="%s" target="_blank" rel="noopener noreferrer">Apply for an account</a> and uniple will issue your API key and webhook secret.',
                        'uniple-checkout-for-woocommerce'
                    ),
                    esc_url('https://forms.gle/b8kwVZeynA1ffV8j6')
                )
            );
            echo '</p></div>';
        }
        parent::admin_options();
    }

    /**
     * Secret field の mask 維持 + capability gate (= manage_woocommerce)。
     *
     * 親 process_admin_options() は WC 側の nonce (woocommerce-settings) を呼出側で検証済の前提。
     */
    public function process_admin_options()
    {
        if (!SettingsSanitizer::userMayManage()) {
            wc_add_notice(__('Insufficient permission.', 'uniple-checkout-for-woocommerce'), 'error');

            return false;
        }

        $existingApiKey = (string) $this->get_option('api_key', '');
        $existingSecret = (string) $this->get_option('webhook_secret', '');
        $existingApiBaseUrl = (string) $this->get_option('api_base_url', UnipleClient::DEFAULT_API_BASE_URL);

        $postedApiBaseUrlKey = 'woocommerce_'.$this->id.'_api_base_url';
        $invalidApiBaseUrl = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce settings API (WC_Admin_Settings) before process_admin_options() runs.
        if (isset($_POST[$postedApiBaseUrlKey])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce settings API (WC_Admin_Settings) before process_admin_options() runs.
            $postedApiBaseUrl = sanitize_text_field((string) wp_unslash($_POST[$postedApiBaseUrlKey]));
            if (UnipleClient::isAllowedApiBaseUrl($postedApiBaseUrl)) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce settings API (WC_Admin_Settings) before process_admin_options() runs.
                $_POST[$postedApiBaseUrlKey] = UnipleClient::normalizeApiBaseUrl($postedApiBaseUrl);
            } else {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce settings API (WC_Admin_Settings) before process_admin_options() runs.
                $_POST[$postedApiBaseUrlKey] = UnipleClient::isAllowedApiBaseUrl($existingApiBaseUrl)
                    ? UnipleClient::normalizeApiBaseUrl($existingApiBaseUrl)
                    : UnipleClient::DEFAULT_API_BASE_URL;
                $invalidApiBaseUrl = true;
            }
        }

        $result = parent::process_admin_options();

        $postedKeyKey = 'woocommerce_'.$this->id.'_api_key';
        $postedSecretKey = 'woocommerce_'.$this->id.'_webhook_secret';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce settings API (WC_Admin_Settings) before process_admin_options() runs.
        $postedKey = isset($_POST[$postedKeyKey]) ? sanitize_text_field((string) wp_unslash($_POST[$postedKeyKey])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce settings API (WC_Admin_Settings) before process_admin_options() runs.
        $postedSecret = isset($_POST[$postedSecretKey]) ? sanitize_text_field((string) wp_unslash($_POST[$postedSecretKey])) : '';

        $this->update_option('api_key', SettingsSanitizer::preserveIfMasked($postedKey, $existingApiKey));
        $this->update_option('webhook_secret', SettingsSanitizer::preserveIfMasked($postedSecret, $existingSecret));

        if ($invalidApiBaseUrl) {
            wc_add_notice(
                __('API base URL must be https://uniple.io or https://dev.uniple.io.', 'uniple-checkout-for-woocommerce'),
                'error'
            );

            return false;
        }

        $syncKey = 'woocommerce_'.$this->id.'_x402_sync';
        $settingsKey = 'woocommerce_'.$this->id.'_x402_settings_save';
        $enabledKey = 'woocommerce_'.$this->id.'_x402_ai_enabled';
        $presentKey = 'woocommerce_'.$this->id.'_x402_ai_enabled_present';
        $settingsRequested = isset($_POST[$settingsKey]) || isset($_POST[$presentKey]);
        $shouldSync = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce settings API (WC_Admin_Settings) before process_admin_options() runs.
        if ($settingsRequested) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce settings API (WC_Admin_Settings) before process_admin_options() runs.
            $enabled = isset($_POST[$enabledKey]) && is_array($_POST[$enabledKey])
                ? array_map('sanitize_text_field', wp_unslash($_POST[$enabledKey]))
                : [];
            $saved = (new ProductSync())->saveAiTargets($enabled);
            if (class_exists('\WC_Admin_Settings')) {
                \WC_Admin_Settings::add_message(sprintf('AI購入対象設定を保存しました。対象商品: %d件', $saved));
            }
            $shouldSync = true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce settings API (WC_Admin_Settings) before process_admin_options() runs.
        if (isset($_POST[$syncKey])) {
            $shouldSync = true;
        }

        if ($shouldSync) {
            $this->runX402ProductSync();
        }

        return $result;
    }

    /**
     * Mask secret values when rendering admin form.
     *
     * WC 親実装は `$this->get_option()` / `$this->settings[$key]` を value に出す。
     * `$data['default']` 上書きだけでは実 secret が DOM に残るため、 描画中だけ
     * settings の該当値を mask に差し替え、 描画後復元する。
     *
     * @param string $key
     * @param array<string, mixed> $data
     */
    public function generate_password_html($key, $data): string
    {
        $existing = (string) $this->get_option($key, '');
        if ($existing === '') {
            return parent::generate_password_html($key, $data);
        }

        $originalSetting = $this->settings[$key] ?? null;
        $this->settings[$key] = SettingsSanitizer::MASK;
        try {
            $html = parent::generate_password_html($key, $data);
        } finally {
            if ($originalSetting === null) {
                unset($this->settings[$key]);
            } else {
                $this->settings[$key] = $originalSetting;
            }
        }

        return $html;
    }

    /**
     * @param string $key
     * @param array<string,mixed> $data
     */
    public function generate_x402_sync_html($key, $data): string
    {
        $buttonName = esc_attr('woocommerce_'.$this->id.'_x402_sync');
        $saveName = esc_attr('woocommerce_'.$this->id.'_x402_settings_save');
        $checkboxName = esc_attr('woocommerce_'.$this->id.'_x402_ai_enabled');
        $presentName = esc_attr('woocommerce_'.$this->id.'_x402_ai_enabled_present');
        $lastSyncMessage = (string) get_option(self::X402_LAST_SYNC_MESSAGE_OPTION, '');
        $lastSyncError = (string) get_option(self::X402_LAST_SYNC_ERROR_OPTION, '');
        $lastResultHtml = '';
        if ($lastSyncError !== '') {
            $lastResultHtml = '<div class="notice notice-error inline" style="margin:12px 0 0;"><p>'.esc_html($lastSyncError).'</p></div>';
        } elseif ($lastSyncMessage !== '') {
            $lastResultHtml = '<div class="notice notice-success inline" style="margin:12px 0 0;"><p>'.esc_html($lastSyncMessage).'</p></div>';
        }
        $rows = '';
        try {
            foreach ((new ProductSync())->listProductSettings() as $product) {
                $checked = !empty($product['aiEnabled']) ? ' checked="checked"' : '';
                $price = $product['priceJpyc'] !== '' ? esc_html($product['priceJpyc'].' JPYC') : '同期対象外';
                $status = !empty($product['ecActive']) ? '有効' : '無効';
                $ecActive = !empty($product['ecActive']) ? '1' : '0';
                $rows .= '<tr>'
                    .'<td><input type="checkbox" class="uniple-x402-ai-target" data-ec-active="'.esc_attr($ecActive).'" name="'.$checkboxName.'[]" value="'.esc_attr($product['externalId']).'"'.$checked.' /></td>'
                    .'<td>'.esc_html($product['name']).'<br><code>'.esc_html($product['externalId']).'</code></td>'
                    .'<td>'.$price.'</td>'
                    .'<td>'.esc_html($status).'</td>'
                    .'</tr>';
            }
        } catch (\Throwable $e) {
            $rows = '<tr><td colspan="4">商品一覧を取得できませんでした。</td></tr>';
        }

        return '<tr valign="top">'
            .'<th scope="row" class="titledesc">'.esc_html((string) ($data['title'] ?? 'x402 / AI購入 商品同期')).'</th>'
            .'<td class="forminp">'
            .'<p>WooCommerceの商品マスタをunipleの商品catalogへ同期します。公開中・購入可能・在庫ありの商品は「有効」として同期されます。</p>'
            .'<p class="description">通常のHosted Checkout / LINE / WalletConnect決済フローは変更されません。</p>'
            .'<input type="hidden" name="'.$presentName.'" value="1" />'
            .'<button type="submit" class="button uniple-x402-submit" name="'.$buttonName.'" value="1">x402商品同期</button>'
            .$lastResultHtml
            .'<p style="margin:12px 0 0;">'
            .'<button type="button" class="button" onclick="unipleX402SetAiTarget(\'all\')">全て選択</button> '
            .'<button type="button" class="button" onclick="unipleX402SetAiTarget(\'none\')">全て解除</button> '
            .'<button type="button" class="button" onclick="unipleX402SetAiTarget(\'ec_active\')">EC側で有効な商品だけ選択</button>'
            .'</p>'
            .'<table class="widefat striped" style="margin-top:12px; max-width:960px;">'
            .'<thead><tr><th>AI購入対象</th><th>商品/バリエーション</th><th>価格</th><th>EC状態</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table>'
            .'<p><button type="submit" class="button uniple-x402-submit" name="'.$saveName.'" value="1">AI購入対象設定を保存</button></p>'
            .'<script>'
            .'function unipleX402AllowSubmit(){window.onbeforeunload=null;}'
            .'function unipleX402SetAiTarget(mode){'
            .'document.querySelectorAll(".uniple-x402-ai-target").forEach(function(checkbox){'
            .'if(mode==="all"){checkbox.checked=true;}'
            .'else if(mode==="none"){checkbox.checked=false;}'
            .'else if(mode==="ec_active"){checkbox.checked=checkbox.getAttribute("data-ec-active")==="1";}'
            .'});'
            .'}'
            .'document.querySelectorAll(".uniple-x402-submit").forEach(function(button){'
            .'button.addEventListener("click", unipleX402AllowSubmit);'
            .'});'
            .'var unipleX402Form=document.getElementById("mainform");'
            .'if(unipleX402Form){unipleX402Form.addEventListener("submit", unipleX402AllowSubmit, true);}'
            .'</script>'
            .'</td></tr>';
    }

    /**
     * @return array<string,string>
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            $message = __('Order not found.', 'uniple-checkout-for-woocommerce');
            wc_add_notice($message, 'error');

            return $this->paymentFailure($message);
        }

        $client = new UnipleClient($this->clientConfig());

        try {
            $amountJpyc = $client->toIntegerJpyc($order->get_total());
        } catch (\InvalidArgumentException $e) {
            wc_get_logger()->error(
                '[uniple-checkout] amount not integer JPYC: '.$e->getMessage(),
                ['source' => 'uniple-checkout', 'order_id' => $order->get_id()]
            );
            $message = __('Order amount must be an integer JPYC value.', 'uniple-checkout-for-woocommerce');
            wc_add_notice($message, 'error');

            return $this->paymentFailure($message);
        }

        $returnUrl = add_query_arg(
            [
                'wc-api' => 'uniple_return',
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ],
            home_url('/')
        );
        $webhookUrl = rest_url('uniple/v1/webhook');
        $cancelUrl = wc_get_checkout_url();

        try {
            $session = $client->createSession([
                'amountJpyc' => $amountJpyc,
                'merchantOrderId' => (string) $order->get_id(),
                'itemName' => sprintf(
                    /* translators: %s: order number */
                    __('WooCommerce order #%s', 'uniple-checkout-for-woocommerce'),
                    (string) $order->get_order_number()
                ),
                'successUrl' => $returnUrl,
                'cancelUrl' => $cancelUrl,
                'webhookUrl' => $webhookUrl,
            ]);
        } catch (\Throwable $e) {
            wc_get_logger()->error(
                '[uniple-checkout] createSession failed: '.$e->getMessage(),
                ['source' => 'uniple-checkout', 'order_id' => $order->get_id()]
            );
            $message = __('Payment gateway is temporarily unavailable. Please try again.', 'uniple-checkout-for-woocommerce');
            wc_add_notice($message, 'error');

            return $this->paymentFailure($message);
        }

        $order->update_meta_data('_uniple_session_id', $session['sessionId']);
        $order->update_meta_data('_uniple_pay_id', $session['payId']);
        $order->update_status('pending', __('uniple checkout session created.', 'uniple-checkout-for-woocommerce'));
        $order->save();

        return [
            'result' => 'success',
            'redirect' => $session['checkoutUrl'],
        ];
    }

    /**
     * @return array{result:string,message:string}
     */
    private function paymentFailure(string $message): array
    {
        return [
            'result' => 'failure',
            'message' => $message,
        ];
    }

    /**
     * @return array{api_key:string, webhook_secret:string, merchant_label:string, api_base_url:string, mode:string}
     */
    public function clientConfig(): array
    {
        return [
            'api_key' => (string) $this->get_option('api_key', ''),
            'webhook_secret' => (string) $this->get_option('webhook_secret', ''),
            'merchant_label' => (string) $this->get_option('merchant_label', ''),
            'api_base_url' => (string) $this->get_option('api_base_url', 'https://uniple.io'),
            'mode' => (string) $this->get_option('mode', 'live'),
        ];
    }

    private function runX402ProductSync(): void
    {
        try {
            $client = new UnipleClient($this->clientConfig());
            $result = (new ProductSync())->syncAll($client);
            $message = sprintf(
                'x402商品同期を実行しました。同期: %d件 / 有効: %d件 / 無効: %d件 / 同期対象外: %d件 (%s)',
                $result['synced'],
                $result['active'],
                $result['inactive'],
                $result['skipped'],
                current_time('mysql')
            );
            update_option(self::X402_LAST_SYNC_MESSAGE_OPTION, $message, false);
            delete_option(self::X402_LAST_SYNC_ERROR_OPTION);
            if (class_exists('\WC_Admin_Settings')) {
                \WC_Admin_Settings::add_message($message);
            } else {
                wc_add_notice($message, 'success');
            }
        } catch (\Throwable $e) {
            wc_get_logger()->error(
                '[uniple-checkout] x402 product sync failed: '.$e->getMessage(),
                ['source' => 'uniple-checkout']
            );
            $message = 'x402商品同期に失敗しました: '.$e->getMessage().' ('.current_time('mysql').')';
            update_option(self::X402_LAST_SYNC_ERROR_OPTION, $message, false);
            if (class_exists('\WC_Admin_Settings')) {
                \WC_Admin_Settings::add_error($message);
            } else {
                wc_add_notice($message, 'error');
            }
        }
    }
}
