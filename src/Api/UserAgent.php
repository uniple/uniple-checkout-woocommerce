<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Api;

use Uniple\CheckoutWooCommerce\Plugin;

defined('ABSPATH') || exit;

final class UserAgent
{
    public static function build(): string
    {
        $wpVersion = (string) (get_bloginfo('version') ?: 'unknown');

        $wcVersion = 'unknown';
        if (function_exists('WC') && WC() instanceof \WooCommerce && WC()->version) {
            $wcVersion = (string) WC()->version;
        } elseif (defined('WC_VERSION')) {
            $wcVersion = (string) constant('WC_VERSION');
        }

        return sprintf(
            'uniple-plugin-woocommerce/%s (WP/%s; WC/%s)',
            Plugin::VERSION,
            $wpVersion,
            $wcVersion
        );
    }
}
