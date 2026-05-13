<?php

declare(strict_types=1);

/**
 * Minimal WP / WC function stubs for pure-function unit tests.
 * Full WP runtime is provided via WP_Mock / WP-Browser in higher tiers.
 */

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '')
    {
        return '6.5.0';
    }
}

if (!class_exists('WooCommerce')) {
    final class WooCommerce
    {
        public string $version = '8.6.0';
    }
}

if (!function_exists('WC')) {
    function WC(): WooCommerce
    {
        static $wc = null;
        if ($wc === null) {
            $wc = new WooCommerce();
        }

        return $wc;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return true;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action): bool
    {
        return $nonce === 'valid-'.$action;
    }
}
