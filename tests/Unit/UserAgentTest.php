<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Uniple\CheckoutWooCommerce\Api\UserAgent;

final class UserAgentTest extends TestCase
{
    public function testBuildEmitsExpectedFormat(): void
    {
        $ua = UserAgent::build();
        self::assertMatchesRegularExpression(
            '#^uniple-plugin-woocommerce/0\.1\.8 \(WP/[^;]+; WC/[^)]+\)$#',
            $ua
        );
    }

    public function testBuildContainsKnownStubVersions(): void
    {
        $ua = UserAgent::build();
        self::assertStringContainsString('WP/6.5.0', $ua);
        self::assertStringContainsString('WC/8.6.0', $ua);
    }
}
