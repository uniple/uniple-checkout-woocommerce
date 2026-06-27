<?php
/*
 * uniple checkout for WooCommerce
 * Copyright (C) 2026 uniple inc.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2,
 * as published by the Free Software Foundation.
 */

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Util;

defined('ABSPATH') || exit;

final class JapaneseAddress
{
    /**
     * @return array{city:string,address1:string,address2:string}
     */
    public static function normalize(string $prefecture, string $city, string $address1, string $address2): array
    {
        $prefecture = self::clean($prefecture);
        $city = self::stripLeadingParts(self::clean($city), [$prefecture]);
        $address1 = self::stripLeadingParts(self::clean($address1), [$prefecture, $city]);
        $address2 = self::stripLeadingParts(self::clean($address2), [$prefecture, $city]);

        if ($address1 === '' && $address2 !== '') {
            $address1 = $address2;
            $address2 = '';
        }

        if ($address2 === $address1) {
            $address2 = '';
        }

        return [
            'city' => $city,
            'address1' => $address1,
            'address2' => $address2,
        ];
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @param array<int,string> $parts
     */
    private static function stripLeadingParts(string $value, array $parts): string
    {
        $result = $value;
        foreach ($parts as $part) {
            $result = self::stripRepeatedPrefix($result, $part);
        }

        return trim($result);
    }

    private static function stripRepeatedPrefix(string $value, string $prefix): string
    {
        $result = trim($value);
        $prefix = trim($prefix);
        if ($prefix === '') {
            return $result;
        }

        while ($result === $prefix || strpos($result, $prefix.' ') === 0) {
            $result = trim(mb_substr($result, mb_strlen($prefix)));
        }

        return $result;
    }
}
