<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Uniple\CheckoutWooCommerce\X402\QuoteStore;

final class QuoteStoreClaimTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['uniple_test_options'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['uniple_test_options'] = [];
    }

    public function testDurableClaimIsExactOwnerOnlyAndSurvivesUsedFinalization(): void
    {
        QuoteStore::save($this->quote());

        $first = QuoteStore::claimUnused('uq_claim_test', $this->owner());
        self::assertSame('acquired', $first['status']);
        self::assertIsArray($first['claim']);
        $claimToken = (string) $first['claim']['claimToken'];

        $same = QuoteStore::claimUnused('uq_claim_test', $this->owner());
        self::assertSame('owned', $same['status']);
        self::assertSame($claimToken, $same['claim']['claimToken']);

        $otherOwner = $this->owner();
        $otherOwner['sessionId'] = 'ucs_other';
        $otherOwner['idempotencyKey'] = 'checkout.session.completed:ucs_other';
        $otherOwner['txHash'] = '0x'.str_repeat('b', 64);
        self::assertSame(
            'conflict',
            QuoteStore::claimUnused('uq_claim_test', $otherOwner)['status']
        );

        self::assertTrue(QuoteStore::attachOrder('uq_claim_test', $claimToken, 123));
        self::assertFalse(QuoteStore::releaseUnstartedClaim('uq_claim_test', $claimToken));
        self::assertTrue(QuoteStore::markUsedByClaim('uq_claim_test', $claimToken, 123));

        $storedQuote = QuoteStore::find('uq_claim_test');
        self::assertNotNull($storedQuote);
        self::assertNotEmpty($storedQuote['usedAt']);
        self::assertSame($claimToken, $storedQuote['usedClaimToken']);
        self::assertSame(123, $storedQuote['usedOrderId']);

        $storedClaim = QuoteStore::findClaim('uq_claim_test');
        self::assertNotNull($storedClaim);
        self::assertSame('used', $storedClaim['state']);
        self::assertSame(123, $storedClaim['orderId']);
        self::assertTrue(QuoteStore::markUsedByClaim('uq_claim_test', $claimToken, 123));
    }

    public function testClaimCannotAttachOrFinalizeForAnotherTokenOrOrder(): void
    {
        QuoteStore::save($this->quote());
        $result = QuoteStore::claimUnused('uq_claim_test', $this->owner());
        $claimToken = (string) $result['claim']['claimToken'];

        self::assertFalse(QuoteStore::attachOrder('uq_claim_test', 'wrong-token', 123));
        self::assertTrue(QuoteStore::attachOrder('uq_claim_test', $claimToken, 123));
        self::assertFalse(QuoteStore::attachOrder('uq_claim_test', $claimToken, 124));
        self::assertFalse(QuoteStore::markUsedByClaim('uq_claim_test', $claimToken, 124));
        self::assertFalse(QuoteStore::markUsedByClaim('uq_claim_test', 'wrong-token', 123));
        self::assertEmpty(QuoteStore::find('uq_claim_test')['usedAt']);
    }

    public function testOnlyProvablyUnstartedClaimCanBeReleased(): void
    {
        QuoteStore::save($this->quote());
        $result = QuoteStore::claimUnused('uq_claim_test', $this->owner());
        $claimToken = (string) $result['claim']['claimToken'];

        self::assertFalse(QuoteStore::releaseUnstartedClaim('uq_claim_test', 'wrong-token'));
        self::assertTrue(QuoteStore::releaseUnstartedClaim('uq_claim_test', $claimToken));
        self::assertNull(QuoteStore::findClaim('uq_claim_test'));

        $next = QuoteStore::claimUnused('uq_claim_test', $this->owner());
        self::assertSame('acquired', $next['status']);
        self::assertNotSame($claimToken, $next['claim']['claimToken']);
    }

    public function testUsedQuoteWithoutClaimCannotBeClaimed(): void
    {
        QuoteStore::save($this->quote(['usedAt' => '2027-01-15T00:00:00+00:00']));

        self::assertSame(
            'used',
            QuoteStore::claimUnused('uq_claim_test', $this->owner())['status']
        );
        self::assertNull(QuoteStore::findClaim('uq_claim_test'));
    }

    public function testUnquotedClaimDurablyBindsOneOrderAndExactPayload(): void
    {
        $owner = [
            'idempotencyKey' => 'checkout.session.completed:legacy-paid',
            'txHash' => '0x'.str_repeat('c', 64),
            'productSku' => 'woocommerce-product-9',
            'amount' => '205',
            'payloadHash' => hash('sha256', 'legacy-payload'),
        ];
        $first = QuoteStore::claimUnquoted($owner);
        self::assertSame('acquired', $first['status']);
        $token = (string) $first['claim']['claimToken'];
        self::assertSame('owned', QuoteStore::claimUnquoted($owner)['status']);

        $changed = $owner;
        $changed['amount'] = '206';
        self::assertSame('conflict', QuoteStore::claimUnquoted($changed)['status']);
        self::assertTrue(QuoteStore::attachUnquotedOrder($owner['idempotencyKey'], $token, 123));
        self::assertFalse(QuoteStore::attachUnquotedOrder($owner['idempotencyKey'], $token, 124));
        self::assertFalse(QuoteStore::completeUnquotedClaim($owner['idempotencyKey'], $token, 124));
        self::assertTrue(QuoteStore::completeUnquotedClaim($owner['idempotencyKey'], $token, 123));
        self::assertTrue(QuoteStore::completeUnquotedClaim($owner['idempotencyKey'], $token, 123));
        self::assertSame('used', QuoteStore::findUnquotedClaim($owner['idempotencyKey'])['state']);
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function quote(array $overrides = []): array
    {
        return array_merge([
            'quoteId' => 'uq_claim_test',
            'productSku' => 'woocommerce-product-1',
            'productId' => 1,
            'quantity' => 1,
            'productSubtotalJpyc' => '55',
            'shippingFeeJpyc' => '150',
            'discountJpyc' => '0',
            'totalJpyc' => '205',
            'expiresAt' => '2027-01-15T00:00:00+00:00',
            'usedAt' => null,
        ], $overrides);
    }

    /**
     * @return array{idempotencyKey:string,sessionId:string,txHash:string,payloadHash:string}
     */
    private function owner(): array
    {
        return [
            'idempotencyKey' => 'checkout.session.completed:ucs_exact',
            'sessionId' => 'ucs_exact',
            'txHash' => '0x'.str_repeat('a', 64),
            'payloadHash' => hash('sha256', 'immutable-q1'),
        ];
    }
}
