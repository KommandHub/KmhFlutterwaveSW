<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit\Checkout\Payment\Service;

use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\FlutterwaveRefundLedger;
use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Client\Resource\Transaction;
use Kommandhub\FlutterwaveSW\Exception\FlutterwaveException;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveCurrencyHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlutterwaveRefundLedger::class)]
#[UsesClass(FlutterwaveCurrencyHelper::class)]
class FlutterwaveRefundLedgerTest extends TestCase
{
    private Transaction&MockObject $transactionResource;
    private FlutterwaveRefundLedger $ledger;

    protected function setUp(): void
    {
        $this->transactionResource = $this->createMock(Transaction::class);
        $flutterwave = $this->createMock(FlutterwaveClient::class);
        $flutterwave->method('transactions')->willReturn($this->transactionResource);
        $this->transactionResource->method('verify')->willReturn(['status' => 'success', 'data' => ['flw_ref' => 'FLW-OURS']]);

        $this->ledger = new FlutterwaveRefundLedger($flutterwave);
    }

    /**
     * Flutterwave ignores the `id` filter and returns the account's refunds.
     * Refund objects carry the parent charge's `flw_ref` (their
     * `transaction_id` is an internal id), so only entries matching the
     * verified transaction's `flw_ref` must survive.
     */
    public function testRefundsForTransactionFiltersByFlwRefClientSide(): void
    {
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => [
            ['id' => 1, 'flw_ref' => 'FLW-OURS', 'transaction_id' => 9784497],
            ['id' => 2, 'flw_ref' => 'FLW-OTHER'],
            ['id' => 3],
            'not-an-array',
        ]]);

        $result = $this->ledger->refundsForTransaction('12345', 'sales-channel-id');

        static::assertCount(1, $result);
        static::assertSame(1, $result[0]['id']);
    }

    public function testRefundsForTransactionWalksEveryPage(): void
    {
        $this->transactionResource->expects(static::exactly(2))
            ->method('refunds')
            ->willReturnCallback(static fn (string $id, ?string $salesChannelId, int $page): array => [
                'status' => 'success',
                'meta' => ['page_info' => ['total_pages' => 2, 'current_page' => $page]],
                'data' => [['id' => $page, 'flw_ref' => 'FLW-OURS']],
            ]);

        static::assertCount(2, $this->ledger->refundsForTransaction('12345', null));
    }

    public function testRefundsForTransactionThrowsWhenTransactionHasNoFlwRef(): void
    {
        $transactionResource = $this->createMock(Transaction::class);
        $transactionResource->method('verify')->willReturn(['status' => 'success', 'data' => []]);
        $flutterwave = $this->createMock(FlutterwaveClient::class);
        $flutterwave->method('transactions')->willReturn($transactionResource);

        $this->expectException(FlutterwaveException::class);

        (new FlutterwaveRefundLedger($flutterwave))->refundsForTransaction('12345', null);
    }

    public function testRefundsForTransactionReturnsEmptyArrayWhenDataMissing(): void
    {
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success']);

        static::assertSame([], $this->ledger->refundsForTransaction('12345', null));
    }

    public function testAlreadyRefundedMinorSumsNonFailedRefunds(): void
    {
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => [
            ['flw_ref' => 'FLW-OURS', 'status' => 'completed', 'amount_refunded' => 30],
            ['flw_ref' => 'FLW-OURS', 'status' => 'successful', 'amount_refunded' => 20],
        ]]);

        static::assertSame(5000, $this->ledger->alreadyRefundedMinor('12345', 'NGN', null));
    }

    /**
     * A failed refund frees its amount back into the refundable balance, so it
     * must not be counted — this is the denylist behaviour the class relies on
     * to fail safe on money (an allowlist that omitted a real "in progress"
     * status would under-count and allow an over-refund).
     */
    public function testAlreadyRefundedMinorExcludesFailedRefunds(): void
    {
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => [
            ['flw_ref' => 'FLW-OURS', 'status' => 'failed', 'amount_refunded' => 100],
        ]]);

        static::assertSame(0, $this->ledger->alreadyRefundedMinor('12345', 'NGN', null));
    }

    public function testAlreadyRefundedMinorIgnoresMalformedEntries(): void
    {
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => [
            'not-an-array',
            ['flw_ref' => 'FLW-OURS', 'status' => 'completed', 'amount_refunded' => 'not-numeric'],
            ['flw_ref' => 'FLW-OURS', 'status' => 'completed', 'amount_refunded' => 20],
        ]]);

        static::assertSame(2000, $this->ledger->alreadyRefundedMinor('12345', 'NGN', null));
    }

    public function testAlreadyRefundedMinorFallsBackToAmountFieldWhenAmountRefundedMissing(): void
    {
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => [
            ['flw_ref' => 'FLW-OURS', 'status' => 'completed', 'amount' => 15],
        ]]);

        static::assertSame(1500, $this->ledger->alreadyRefundedMinor('12345', 'NGN', null));
    }

    public function testAlreadyRefundedMinorExcludesForeignTransactionRefunds(): void
    {
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => [
            ['flw_ref' => 'FLW-OTHER', 'status' => 'completed', 'amount_refunded' => 100],
        ]]);

        static::assertSame(0, $this->ledger->alreadyRefundedMinor('12345', 'NGN', null));
    }
}
