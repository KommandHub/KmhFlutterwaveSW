<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit\Client\Resource;

use Kommandhub\FlutterwaveSW\Client\Http\HttpClientInterface;
use Kommandhub\FlutterwaveSW\Client\Resource\ApiResource;
use Kommandhub\FlutterwaveSW\Client\Resource\Transaction;
use Kommandhub\FlutterwaveSW\Exception\FlutterwaveException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(Transaction::class)]
#[CoversClass(ApiResource::class)]
class TransactionTest extends TestCase
{
    use MocksFlutterwaveResponses;

    private HttpClientInterface&MockObject $httpClient;
    private Transaction $transaction;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->transaction = new Transaction($this->httpClient);
    }

    public function testInitializePostsToPaymentsAndReturnsDecodedBody(): void
    {
        $payload = ['amount' => 100, 'currency' => 'NGN'];

        $this->httpClient->expects(static::once())
            ->method('post')
            ->with('/payments', $payload, 'sales-channel-id')
            ->willReturn($this->respondWith([
                'status' => 'success',
                'data' => ['link' => 'https://flutterwave.com/pay/x'],
            ]));

        static::assertSame([
            'status' => 'success',
            'data' => ['link' => 'https://flutterwave.com/pay/x'],
        ], $this->transaction->initialize($payload, 'sales-channel-id'));
    }

    public function testVerifyGetsByTransactionId(): void
    {
        $this->httpClient->expects(static::once())
            ->method('get')
            ->with('/transactions/12345/verify', [], null)
            ->willReturn($this->respondWith(['status' => 'success']));

        static::assertSame(['status' => 'success'], $this->transaction->verify('12345'));
    }

    /**
     * The webhook path verifies by tx_ref precisely so a replayed payload cannot
     * point verification at a transaction of the attacker's choosing.
     */
    public function testVerifyByReferenceGetsByTxRef(): void
    {
        $this->httpClient->expects(static::once())
            ->method('get')
            ->with('/transactions/verify_by_reference', ['tx_ref' => 'abc-123'], null)
            ->willReturn($this->respondWith(['status' => 'success']));

        static::assertSame(['status' => 'success'], $this->transaction->verifyByReference('abc-123'));
    }

    public function testRefundSendsAmountAndComments(): void
    {
        $this->httpClient->expects(static::once())
            ->method('post')
            ->with('/transactions/12345/refund', ['amount' => 69.0, 'comments' => 'Out of stock'], null)
            ->willReturn($this->respondWith(['status' => 'success']));

        $this->transaction->refund('12345', 69.0, 'Out of stock');
    }

    /**
     * Flutterwave treats an absent amount as "refund everything". Sending
     * `amount: null` is not the same thing, so the key must be omitted.
     */
    public function testFullRefundOmitsTheAmountKeyEntirely(): void
    {
        $this->httpClient->expects(static::once())
            ->method('post')
            ->with('/transactions/12345/refund', [], null)
            ->willReturn($this->respondWith(['status' => 'success']));

        $this->transaction->refund('12345');
    }

    public function testRefundOmitsCommentsWhenNotGiven(): void
    {
        $this->httpClient->expects(static::once())
            ->method('post')
            ->with('/transactions/12345/refund', ['amount' => 10.0], null)
            ->willReturn($this->respondWith(['status' => 'success']));

        $this->transaction->refund('12345', 10.0);
    }

    public function testRefundsListsRefundsViaTheRefundsCollectionFilteredById(): void
    {
        // Must hit GET /refunds?id=... — the /transactions/{id}/refunds path is
        // POST-only for creating a refund; the GET does not exist and returns
        // non-JSON ("Syntax error for URL").
        $this->httpClient->expects(static::once())
            ->method('get')
            ->with('/refunds', ['id' => '12345', 'page' => 1], null)
            ->willReturn($this->respondWith(['status' => 'success', 'data' => []]));

        $this->transaction->refunds('12345');
    }

    public function testListForwardsQueryParameters(): void
    {
        $this->httpClient->expects(static::once())
            ->method('get')
            ->with('/transactions', ['from' => '2026-01-01'], null)
            ->willReturn($this->respondWith(['status' => 'success']));

        $this->transaction->list(['from' => '2026-01-01']);
    }

    /**
     * ApiResource passes `false` to toArray() for this reason: Flutterwave
     * returns a JSON error body alongside a 4xx. Throwing on the status code
     * would discard that body and hide Flutterwave's actionable message, leaving
     * every `status === 'error'` branch in the plugin unreachable.
     */
    public function testErrorBodyOnA4xxSurvivesInsteadOfThrowing(): void
    {
        $this->httpClient->method('get')->willReturn($this->respondWith([
            'status' => 'error',
            'message' => 'No transaction was found for this id',
            'data' => null,
        ], 404));

        static::assertSame([
            'status' => 'error',
            'message' => 'No transaction was found for this id',
            'data' => null,
        ], $this->transaction->verify('nope'));
    }

    /**
     * A body that is not JSON at all is a genuine fault (a proxy error page, an
     * outage) and must not be mistaken for an API-level error response.
     */
    public function testNonJsonBodyIsWrappedAsFlutterwaveException(): void
    {
        $this->httpClient->method('get')->willReturn($this->respondWith('<html>502 Bad Gateway</html>', 502));

        $this->expectException(FlutterwaveException::class);

        $this->transaction->verify('12345');
    }
}
