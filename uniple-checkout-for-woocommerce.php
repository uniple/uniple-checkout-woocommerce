<?php
/**
 * Plugin Name: uniple checkout for WooCommerce
 * Plugin URI: https://uniple.io/
 * Description: JPYC hosted checkout for WooCommerce, powered by uniple.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: uniple
 * Author URI: https://uniple.io/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: uniple-checkout-for-woocommerce
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 8.5
 * WC tested up to: 10.7
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Uniple\\CheckoutWooCommerce\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__.'/src/'.str_replace('\\', '/', $relative).'.php';
        if (file_exists($path)) {
            require_once $path;
        }
    });
}

add_action('before_woocommerce_init', static function (): void {
    if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        return;
    }
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
        'custom_order_tables',
        __FILE__,
        true
    );
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
        'cart_checkout_blocks',
        __FILE__,
        true
    );
});

add_action('plugins_loaded', [\Uniple\CheckoutWooCommerce\Plugin::class, 'boot']);
