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
