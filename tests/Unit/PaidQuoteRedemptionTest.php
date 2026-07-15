<?php

declare(strict_types=1);

namespace Uniple\CheckoutWooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Uniple\CheckoutWooCommerce\X402\PaidQuoteRedemption;

final class PaidQuoteRedemptionTest extends TestCase
{
    private const EXPIRES_AT = 1_800_000_000;

    public function testExpiredQuoteCanBeRedeemedAtAuthorizationSafetyBoundary(): void
    {
        self::assertNull(PaidQuoteRedemption::expiryValidationError(
            $this->quote(),
            $this->paidData(self::EXPIRES_AT - PaidQuoteRedemption::AUTHORIZATION_QUOTE_SAFETY_SECONDS),
            self::EXPIRES_AT + 10
        ));
    }

    public function testUnexpiredQuoteKeepsLegacyCompatibilityWithoutPaidProof(): void
    {
        self::assertNull(PaidQuoteRedemption::expiryValidationError(
            $this->quote(),
            [],
            self::EXPIRES_AT - 1
        ));
    }

    public function testExpiredQuoteRequiresIntegerAuthorizationBoundBeforeCutoff(): void
    {
        $missing = $this->paidData(self::EXPIRES_AT - 30);
        unset($missing['paymentAuthorizationValidBefore']);
        self::assertSame(
            'payment_authorization_valid_before_missing',
            PaidQuoteRedemption::expiryValidationError($this->quote(), $missing, self::EXPIRES_AT + 10)
        );

        self::assertSame(
            'payment_authorization_valid_before_invalid',
            PaidQuoteRedemption::expiryValidationError(
                $this->quote(),
                $this->paidData((string) (self::EXPIRES_AT - 30)),
                self::EXPIRES_AT + 10
            )
        );
        self::assertSame(
            'payment_authorization_valid_before_invalid',
            PaidQuoteRedemption::expiryValidationError(
                $this->quote(),
                $this->paidData(self::EXPIRES_AT - 30.5),
                self::EXPIRES_AT + 10
            )
        );
        self::assertSame(
            'payment_authorization_after_quote_expiry',
            PaidQuoteRedemption::expiryValidationError(
                $this->quote(),
                $this->paidData(self::EXPIRES_AT - 29),
                self::EXPIRES_AT + 10
            )
        );
    }

    public function testExpiredQuoteRequiresValidTransactionAndEveryExactQuoteField(): void
    {
        $invalidTx = $this->paidData(self::EXPIRES_AT - 30);
        $invalidTx['txHash'] = '0xabc';
        self::assertSame(
            'paid_redemption_tx_hash_missing_or_invalid',
            PaidQuoteRedemption::expiryValidationError($this->quote(), $invalidTx, self::EXPIRES_AT + 10)
        );

        $missingDiscount = $this->paidData(self::EXPIRES_AT - 30);
        unset($missingDiscount['discountJpyc']);
        self::assertSame(
            'quote_discount_missing',
            PaidQuoteRedemption::expiryValidationError($this->quote(), $missingDiscount, self::EXPIRES_AT + 10)
        );

        $shippingMismatch = $this->paidData(self::EXPIRES_AT - 30);
        $shippingMismatch['shippingFeeJpyc'] = '149';
        self::assertSame(
            'quote_shipping_fee_mismatch',
            PaidQuoteRedemption::expiryValidationError($this->quote(), $shippingMismatch, self::EXPIRES_AT + 10)
        );

        $quantityMismatch = $this->paidData(self::EXPIRES_AT - 30);
        $quantityMismatch['quantity'] = 2;
        self::assertSame(
            'quote_quantity_mismatch',
            PaidQuoteRedemption::expiryValidationError($this->quote(), $quantityMismatch, self::EXPIRES_AT + 10)
        );

        $missingSource = $this->paidData(self::EXPIRES_AT - 30);
        unset($missingSource['quoteSource']);
        self::assertSame(
            'quote_source_missing',
            PaidQuoteRedemption::expiryValidationError($this->quote(), $missingSource, self::EXPIRES_AT + 10)
        );

        $expiryMismatch = $this->paidData(self::EXPIRES_AT - 30);
        $expiryMismatch['quoteExpiresAt'] = gmdate(DATE_ATOM, self::EXPIRES_AT + 1);
        self::assertSame(
            'quote_expires_at_mismatch',
            PaidQuoteRedemption::expiryValidationError($this->quote(), $expiryMismatch, self::EXPIRES_AT + 10)
        );
    }

    public function testDuplicateTransactionIdentityIsCaseInsensitiveButNeverInterchangeable(): void
    {
        self::assertTrue(PaidQuoteRedemption::transactionMatches('0xAbC', '0xabc'));
        self::assertFalse(PaidQuoteRedemption::transactionMatches('0xabc', '0xdef'));
        self::assertFalse(PaidQuoteRedemption::transactionMatches(null, '0xabc'));
    }

    /**
     * @return array<string,mixed>
     */
    private function quote(): array
    {
        return [
            'quoteId' => 'uq_test_quote',
            'quantity' => 1,
            'productSubtotalJpyc' => '55',
            'shippingFeeJpyc' => '150',
            'discountJpyc' => '0',
            'totalJpyc' => '205',
            'expiresAt' => gmdate(DATE_ATOM, self::EXPIRES_AT),
            'quoteSource' => 'woocommerce',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function paidData(mixed $validBefore): array
    {
        return [
            'quoteId' => 'uq_test_quote',
            'quantity' => 1,
            'productSubtotalJpyc' => '55',
            'shippingFeeJpyc' => '150',
            'discountJpyc' => '0',
            'totalJpyc' => '205',
            'txHash' => '0x'.str_repeat('a', 64),
            'paymentAuthorizationValidBefore' => $validBefore,
            'quoteSource' => 'woocommerce',
            'quoteExpiresAt' => gmdate(DATE_ATOM, self::EXPIRES_AT),
        ];
    }
}
