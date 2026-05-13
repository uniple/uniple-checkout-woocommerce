<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uniple\CheckoutWooCommerce\Api\UnipleClient;

final class UnipleClientPureTest extends TestCase
{
    private function makeClient(string $secret = 'whsec_test'): UnipleClient
    {
        return new UnipleClient([
            'api_key' => 'sk_test',
            'webhook_secret' => $secret,
            'merchant_label' => 'demo',
            'api_base_url' => 'https://uniple.io',
            'mode' => 'live',
        ]);
    }

    public function testToIntegerJpycAcceptsIntegerString(): void
    {
        self::assertSame(50, $this->makeClient()->toIntegerJpyc('50'));
        self::assertSame(0, $this->makeClient()->toIntegerJpyc('0'));
        self::assertSame(123456, $this->makeClient()->toIntegerJpyc('123456'));
    }

    public function testToIntegerJpycAcceptsDecimalZeroSuffix(): void
    {
        self::assertSame(50, $this->makeClient()->toIntegerJpyc('50.0'));
        self::assertSame(50, $this->makeClient()->toIntegerJpyc('50.00'));
        self::assertSame(50, $this->makeClient()->toIntegerJpyc('50.000'));
    }

    public function testToIntegerJpycAcceptsNumericTypes(): void
    {
        self::assertSame(50, $this->makeClient()->toIntegerJpyc(50));
        self::assertSame(50, $this->makeClient()->toIntegerJpyc(50.0));
    }

    public function testToIntegerJpycRejectsDecimal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient()->toIntegerJpyc('50.5');
    }

    public function testToIntegerJpycRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient()->toIntegerJpyc('');
    }

    public function testToIntegerJpycRejectsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient()->toIntegerJpyc(null);
    }

    public function testToIntegerJpycRejectsNonNumeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient()->toIntegerJpyc('abc');
    }

    public function testVerifySignatureAcceptsValid(): void
    {
        $client = $this->makeClient('whsec_test');
        $body = '{"foo":"bar"}';
        $sig = 'sha256='.hash_hmac('sha256', $body, 'whsec_test');
        self::assertTrue($client->verifySignature($body, $sig));
    }

    public function testVerifySignatureAcceptsWithoutPrefix(): void
    {
        $client = $this->makeClient('whsec_test');
        $body = '{"foo":"bar"}';
        $sig = hash_hmac('sha256', $body, 'whsec_test');
        self::assertTrue($client->verifySignature($body, $sig));
    }

    public function testVerifySignatureRejectsWrongSecret(): void
    {
        $client = $this->makeClient('whsec_test');
        $body = '{"foo":"bar"}';
        $sig = 'sha256='.hash_hmac('sha256', $body, 'whsec_other');
        self::assertFalse($client->verifySignature($body, $sig));
    }

    public function testVerifySignatureRejectsTamperedBody(): void
    {
        $client = $this->makeClient('whsec_test');
        $sig = 'sha256='.hash_hmac('sha256', '{"foo":"bar"}', 'whsec_test');
        self::assertFalse($client->verifySignature('{"foo":"baz"}', $sig));
    }

    public function testVerifySignatureRejectsEmptyInputs(): void
    {
        $client = $this->makeClient('whsec_test');
        self::assertFalse($client->verifySignature('', ''));
        self::assertFalse($client->verifySignature('body', ''));
        self::assertFalse($client->verifySignature('body', 'sha256=deadbeef'));
    }
}
