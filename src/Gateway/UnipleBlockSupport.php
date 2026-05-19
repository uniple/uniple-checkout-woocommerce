<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Gateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Uniple\CheckoutWooCommerce\Plugin;

defined('ABSPATH') || exit;

/**
 * WC Blocks (= Cart / Checkout Blocks) 経路用 redirect-only payment method。
 *
 * card field は提示せず、 Blocks UI からも `process_payment` 経由で
 * uniple checkout へ redirect される。 server settings は UnipleGateway と共有。
 */
final class UnipleBlockSupport extends AbstractPaymentMethodType
{
    /** @var string */
    protected $name = Plugin::PLUGIN_ID;

    public function initialize(): void
    {
        $this->settings = (array) get_option('woocommerce_'.Plugin::PLUGIN_ID.'_settings', []);
    }

    public function is_active(): bool
    {
        return ($this->settings['enabled'] ?? 'no') === 'yes';
    }

    /**
     * @return array<int, string>
     */
    public function get_payment_method_script_handles(): array
    {
        $handle = 'uniple-checkout-blocks';
        $assetUrl = plugins_url('assets/js/blocks-payment.js', dirname(__DIR__, 2).'/uniple-checkout-for-woocommerce.php');

        wp_register_script(
            $handle,
            $assetUrl,
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities'],
            Plugin::VERSION,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'uniple-checkout-for-woocommerce');
        }

        return [$handle];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        return [
            'name' => $this->name,
            'title' => (string) ($this->settings['title'] ?? __('uniple checkout (JPYC)', 'uniple-checkout-for-woocommerce')),
            'label' => (string) ($this->settings['title'] ?? __('uniple checkout (JPYC)', 'uniple-checkout-for-woocommerce')),
            'description' => (string) ($this->settings['description'] ?? __('Pay with JPYC via uniple hosted checkout.', 'uniple-checkout-for-woocommerce')),
            'supports' => ['products'],
        ];
    }
}
