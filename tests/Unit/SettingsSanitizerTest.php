<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Uniple\CheckoutWooCommerce\Admin\SettingsSanitizer;

final class SettingsSanitizerTest extends TestCase
{
    public function testPreserveReturnsExistingWhenBlankSubmitted(): void
    {
        self::assertSame('existing', SettingsSanitizer::preserveIfMasked('', 'existing'));
        self::assertSame('existing', SettingsSanitizer::preserveIfMasked('   ', 'existing'));
    }

    public function testPreserveReturnsExistingWhenMaskSubmitted(): void
    {
        self::assertSame(
            'existing',
            SettingsSanitizer::preserveIfMasked(SettingsSanitizer::MASK, 'existing')
        );
    }

    public function testPreserveReturnsSubmittedWhenNewValue(): void
    {
        self::assertSame('new-secret', SettingsSanitizer::preserveIfMasked('new-secret', 'existing'));
        self::assertSame('new', SettingsSanitizer::preserveIfMasked('  new  ', 'existing'));
    }

    public function testMaskForDisplayReturnsMaskForNonEmpty(): void
    {
        self::assertSame(SettingsSanitizer::MASK, SettingsSanitizer::maskForDisplay('anything'));
    }

    public function testMaskForDisplayReturnsEmptyForEmpty(): void
    {
        self::assertSame('', SettingsSanitizer::maskForDisplay(''));
    }
}
