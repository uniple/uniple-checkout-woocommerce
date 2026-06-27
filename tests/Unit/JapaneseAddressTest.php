<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Uniple\CheckoutWooCommerce\Util\JapaneseAddress;

final class JapaneseAddressTest extends TestCase
{
    public function testNormalizePromotesStreetWhenAddress1OnlyRepeatsCity(): void
    {
        self::assertSame(
            [
                'city' => '世田谷区',
                'address1' => '喜多見 123',
                'address2' => '',
            ],
            JapaneseAddress::normalize('東京都', '世田谷区', '世田谷区 世田谷区', '喜多見 123')
        );
    }

    public function testNormalizeKeepsNormalAddressLines(): void
    {
        self::assertSame(
            [
                'city' => '千代田区',
                'address1' => '千代田1-1',
                'address2' => 'テストビル101',
            ],
            JapaneseAddress::normalize('東京都', '千代田区', '千代田1-1', 'テストビル101')
        );
    }
}
