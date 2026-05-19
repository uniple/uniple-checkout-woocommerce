<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Gateway;

use Uniple\CheckoutWooCommerce\Admin\SettingsSanitizer;
use Uniple\CheckoutWooCommerce\Api\UnipleClient;
use Uniple\CheckoutWooCommerce\Plugin;
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
    public function __construct()
    {
        $this->id = Plugin::PLUGIN_ID;
        $this->method_title = __('uniple checkout (JPYC)', 'uniple-checkout-woocommerce');
        $this->method_description = __(
            'JPYC stablecoin hosted checkout, powered by uniple. Redirects to uniple checkout page.',
            'uniple-checkout-woocommerce'
        );
        $this->has_fields = false;
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = (string) $this->get_option('title', __('uniple checkout (JPYC)', 'uniple-checkout-woocommerce'));
        $this->description = (string) $this->get_option(
            'description',
            __('Pay with JPYC via uniple hosted checkout.', 'uniple-checkout-woocommerce')
        );

        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable / Disable', 'uniple-checkout-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable uniple checkout (JPYC)', 'uniple-checkout-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'uniple-checkout-woocommerce'),
                'type' => 'text',
                'default' => __('uniple checkout (JPYC)', 'uniple-checkout-woocommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'uniple-checkout-woocommerce'),
                'type' => 'textarea',
                'default' => __('Pay with JPYC via uniple hosted checkout.', 'uniple-checkout-woocommerce'),
            ],
            'api_base_url' => [
                'title' => __('API base URL', 'uniple-checkout-woocommerce'),
                'type' => 'text',
                'default' => 'https://uniple.io',
                'desc_tip' => true,
            ],
            'mode' => [
                'title' => __('Mode', 'uniple-checkout-woocommerce'),
                'type' => 'select',
                'options' => [
                    'live' => __('Live', 'uniple-checkout-woocommerce'),
                    'test' => __('Test', 'uniple-checkout-woocommerce'),
                ],
                'default' => 'live',
            ],
            'merchant_label' => [
                'title' => __('Merchant label', 'uniple-checkout-woocommerce'),
                'type' => 'text',
                'default' => '',
            ],
            'api_key' => [
                'title' => __('API key', 'uniple-checkout-woocommerce'),
                'type' => 'password',
                'default' => '',
                'description' => __('Issued by uniple admin. Stored masked; re-enter to update.', 'uniple-checkout-woocommerce'),
            ],
            'webhook_secret' => [
                'title' => __('Webhook secret', 'uniple-checkout-woocommerce'),
                'type' => 'password',
                'default' => '',
                'description' => __('HMAC-SHA256 webhook signing secret. Stored masked; re-enter to update.', 'uniple-checkout-woocommerce'),
            ],
        ];
    }

    /**
     * Secret field の mask 維持 + capability gate (= manage_woocommerce)。
     *
     * 親 process_admin_options() は WC 側の nonce (woocommerce-settings) を呼出側で検証済の前提。
     */
    public function process_admin_options()
    {
        if (!SettingsSanitizer::userMayManage()) {
            wc_add_notice(__('Insufficient permission.', 'uniple-checkout-woocommerce'), 'error');

            return false;
        }

        $existingApiKey = (string) $this->get_option('api_key', '');
        $existingSecret = (string) $this->get_option('webhook_secret', '');
        $existingApiBaseUrl = (string) $this->get_option('api_base_url', UnipleClient::DEFAULT_API_BASE_URL);

        $postedApiBaseUrlKey = 'woocommerce_'.$this->id.'_api_base_url';
        $invalidApiBaseUrl = false;
        if (isset($_POST[$postedApiBaseUrlKey])) {
            $postedApiBaseUrl = sanitize_text_field((string) wp_unslash((string) $_POST[$postedApiBaseUrlKey]));
            if (UnipleClient::isAllowedApiBaseUrl($postedApiBaseUrl)) {
                $_POST[$postedApiBaseUrlKey] = UnipleClient::normalizeApiBaseUrl($postedApiBaseUrl);
            } else {
                $_POST[$postedApiBaseUrlKey] = UnipleClient::isAllowedApiBaseUrl($existingApiBaseUrl)
                    ? UnipleClient::normalizeApiBaseUrl($existingApiBaseUrl)
                    : UnipleClient::DEFAULT_API_BASE_URL;
                $invalidApiBaseUrl = true;
            }
        }

        $result = parent::process_admin_options();

        $postedKeyKey = 'woocommerce_'.$this->id.'_api_key';
        $postedSecretKey = 'woocommerce_'.$this->id.'_webhook_secret';
        $postedKey = isset($_POST[$postedKeyKey]) ? (string) wp_unslash((string) $_POST[$postedKeyKey]) : '';
        $postedSecret = isset($_POST[$postedSecretKey]) ? (string) wp_unslash((string) $_POST[$postedSecretKey]) : '';

        $this->update_option('api_key', SettingsSanitizer::preserveIfMasked($postedKey, $existingApiKey));
        $this->update_option('webhook_secret', SettingsSanitizer::preserveIfMasked($postedSecret, $existingSecret));

        if ($invalidApiBaseUrl) {
            wc_add_notice(
                __('API base URL must be https://uniple.io or https://dev.uniple.io.', 'uniple-checkout-woocommerce'),
                'error'
            );

            return false;
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
     * @return array<string,string>|null
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Order not found.', 'uniple-checkout-woocommerce'), 'error');

            return null;
        }

        $client = new UnipleClient($this->clientConfig());

        try {
            $amountJpyc = $client->toIntegerJpyc($order->get_total());
        } catch (\InvalidArgumentException $e) {
            wc_get_logger()->error(
                '[uniple-checkout] amount not integer JPYC: '.$e->getMessage(),
                ['source' => 'uniple-checkout', 'order_id' => $order->get_id()]
            );
            wc_add_notice(__('Order amount must be an integer JPYC value.', 'uniple-checkout-woocommerce'), 'error');

            return null;
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

        try {
            $session = $client->createSession([
                'amountJpyc' => $amountJpyc,
                'merchantOrderId' => (string) $order->get_id(),
                'itemName' => sprintf(
                    /* translators: %s: order number */
                    __('WooCommerce order #%s', 'uniple-checkout-woocommerce'),
                    (string) $order->get_order_number()
                ),
                'successUrl' => $returnUrl,
                'cancelUrl' => $order->get_cancel_order_url_raw(),
                'webhookUrl' => $webhookUrl,
            ]);
        } catch (\Throwable $e) {
            wc_get_logger()->error(
                '[uniple-checkout] createSession failed: '.$e->getMessage(),
                ['source' => 'uniple-checkout', 'order_id' => $order->get_id()]
            );
            wc_add_notice(__('Payment gateway is temporarily unavailable. Please try again.', 'uniple-checkout-woocommerce'), 'error');

            return null;
        }

        $order->update_meta_data('_uniple_session_id', $session['sessionId']);
        $order->update_meta_data('_uniple_pay_id', $session['payId']);
        $order->update_status('pending', __('uniple checkout session created.', 'uniple-checkout-woocommerce'));
        $order->save();

        return [
            'result' => 'success',
            'redirect' => $session['checkoutUrl'],
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
}
