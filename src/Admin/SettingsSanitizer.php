<?php

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
        if (!isset($_POST[$fieldName])) {
            return false;
        }
        $nonce = sanitize_text_field((string) wp_unslash((string) $_POST[$fieldName]));

        return (bool) wp_verify_nonce($nonce, $action);
    }
}
