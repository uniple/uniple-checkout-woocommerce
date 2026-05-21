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

namespace Uniple\CheckoutWooCommerce\Admin;

defined('ABSPATH') || exit;

/**
 * Admin settings 用 sanitize / mask helper。
 *
 * - secret 系 field は password input + esc_attr + mask 表示
 * - 既存値ありかつ POST が空または mask placeholder の場合は既存値を維持
 * - mask 解除には capability `manage_woocommerce` + nonce 検証必須
 */
final class SettingsSanitizer
{
    public const MASK = '••••••••';

    public static function preserveIfMasked(string $newValue, string $existing): string
    {
        $newValue = trim($newValue);
        if ($newValue === '' || $newValue === self::MASK) {
            return $existing;
        }

        return $newValue;
    }

    public static function maskForDisplay(string $value): string
    {
        return $value !== '' ? self::MASK : '';
    }

    public static function userMayManage(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public static function verifyNonceFromRequest(string $action, string $fieldName = '_wpnonce'): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This helper reads the posted nonce field in order to verify it with wp_verify_nonce().
        if (!isset($_POST[$fieldName])) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This helper reads the posted nonce field in order to verify it with wp_verify_nonce().
        $nonce = sanitize_text_field((string) wp_unslash($_POST[$fieldName]));

        return (bool) wp_verify_nonce($nonce, $action);
    }
}
