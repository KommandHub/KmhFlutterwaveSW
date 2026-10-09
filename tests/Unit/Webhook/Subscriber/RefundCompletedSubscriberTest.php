<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit\Webhook\Subscriber;

use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\RefundProcessor;
use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Client\Resource\Refund;
use Kommandhub\FlutterwaveSW\Exception\FlutterwaveException;
use Kommandhub\FlutterwaveSW\Logging\ConfigurableLogger;
use Kommandhub\FlutterwaveSW\Service\OrderTransactionService;
use Kommandhub\FlutterwaveSW\Webhook\Event\RefundCompletedEvent;
use Kommandhub\FlutterwaveSW\Webhook\Service\WebhookDeduplicator;
use Kommandhub\FlutterwaveSW\Webhook\Subscriber\RefundCompletedSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Payment\Cart\RefundPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

#[CoversClass(RefundCompletedSubscriber::class)]
#[UsesClass(RefundCompletedEvent::class)]
#[UsesClass(WebhookDeduplicator::class)]
class RefundCompletedSubscriberTest extends TestCase
{
    private OrderTransactionService&MockObject $orderTransactionService;
    private OrderTransactionCaptureRefundStateHandler&MockObject $refundStateHandler;
    private RefundProcessor&MockObject $refundProcessor;
    private WebhookDeduplicator&MockObject $deduplicator;
    private ConfigurableLogger&MockObject $logger;
    private RefundCompletedSubscriber $subscriber;

    /**
     * What Flutterwave's API reports for the refund; the subscriber must act on
     * this, never on the webhook payload's own status.
     *
     * @var array<string, mixed>
     */
    private array $apiResponse = ['status' => 'success', 'data' => ['status' => 'completed']];

    protected function setUp(): void
    {
        $this->orderTransactionService = $this->createMock(OrderTransactionService::class);
        $this->refundStateHandler = $this->createMock(OrderTransactionCaptureRefundStateHandler::class);
        $this->refundProcessor = $this->createMock(RefundProcessor::class);
        $this->deduplicator = $this->createMock(WebhookDeduplicator::class);
        $this->logger = $this->createMock(ConfigurableLogger::class);

        $refundResource = $this->createMock(Refund::class);
        $refundResource->method('fetch')->willReturnCallback(fn (): array => $this->apiResponse);
        $flutterwave = $this->createMock(FlutterwaveClient::class);
        $flutterwave->method('refunds')->willReturn($refundResource);

        $this->subscriber = new RefundCompletedSubscriber(
            $this->orderTransactionService,
            $flutterwave,
            $this->refundStateHandler,
            $this->refundProcessor,
            $this->deduplicator,
            $this->logger
        );
    }

    private function refund(string $state = 'open'): OrderTransactionCaptureRefundEntity
    {
        $refund = new OrderTransactionCaptureRefundEntity();
        $refund->setId('refund-id');

        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);
        $refund->setStateMachineState($stateEntity);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('order-transaction-id');
        $transaction->setCustomFields([]);

        $capture = new OrderTransactionCaptureEntity();
        $capture->setId('capture-id');
        $capture->setOrderTransactionId('order-transaction-id');
        $capture->setTransaction($transaction);
        $refund->setTransactionCapture($capture);

        return $refund;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function event(array $data): RefundCompletedEvent
    {
        return new RefundCompletedEvent($data, Context::createDefaultContext());
    }

    /**
     * The correlation contract: the webhook's refund id is looked up against the
     * externalReference stored when the refund was created.
     */
    public function testCompletesThePendingRefundMatchedByFlutterwaveRefundId(): void
    {
        $this->orderTransactionService->expects(static::once())
            ->method('findRefundByExternalReference')
            ->with('8612')
            ->willReturn($this->refund());

        $this->refundProcessor->expects(static::once())
            ->method('process')
            ->with(static::callback(static fn (RefundPaymentTransactionStruct $struct): bool => $struct->getRefundId() === 'refund-id'
                && $struct->getOrderTransactionId() === 'order-transaction-id'));
        $this->deduplicator->expects(static::once())->method('markProcessed');

        ($this->subscriber)($this->event(['id' => 8612, 'TransactionId' => 5708, 'status' => 'completed']));
    }

    /**
     * Flutterwave uses both spellings for a settled refund.
     *
     * @return array<string, array{string}>
     */
    public static function successStatusProvider(): array
    {
        return ['completed' => ['completed'], 'successful' => ['successful'], 'uppercase' => ['COMPLETED']];
    }

    #[DataProvider('successStatusProvider')]
    public function testSettledStatusesComplete(string $status): void
    {
        $this->apiResponse = ['status' => 'success', 'data' => ['status' => $status]];
        $this->orderTransactionService->method('findRefundByExternalReference')->willReturn($this->refund());

        $this->refundProcessor->expects(static::once())->method('process');

        ($this->subscriber)($this->event(['id' => 8612, 'status' => $status]));
    }

    public function testFailedRefundFailsTheRecord(): void
    {
        $this->apiResponse = ['status' => 'success', 'data' => ['status' => 'failed']];
        $this->orderTransactionService->method('findRefundByExternalReference')->willReturn($this->refund());

        $this->refundStateHandler->expects(static::once())->method('fail')->with('refund-id');
        $this->refundStateHandler->expects(static::never())->method('complete');

        ($this->subscriber)($this->event(['id' => 8612, 'status' => 'failed']));
    }

    /**
     * A refund still in flight must stay pending AND must not be marked
     * processed, or the later delivery carrying the final status would be
     * discarded as a duplicate.
     */
    public function testNonFinalStatusLeavesTheRefundPendingAndUnmarked(): void
    {
        $this->apiResponse = ['status' => 'success', 'data' => ['status' => 'pending']];
        $this->orderTransactionService->method('findRefundByExternalReference')->willReturn($this->refund());

        $this->refundStateHandler->expects(static::never())->method('complete');
        $this->refundStateHandler->expects(static::never())->method('fail');
        $this->deduplicator->expects(static::never())->method('markProcessed');

        ($this->subscriber)($this->event(['id' => 8612, 'status' => 'pending']));
    }

    /**
     * `verif-hash` does not sign the body, so a replayed delivery can claim any
     * status. Flutterwave's API is the only source of truth.
     */
    public function testPayloadStatusIsIgnoredInFavourOfTheApi(): void
    {
        $this->apiResponse = ['status' => 'success', 'data' => ['status' => 'failed']];
        $this->orderTransactionService->method('findRefundByExternalReference')->willReturn($this->refund());

        $this->refundProcessor->expects(static::never())->method('process');
        $this->refundStateHandler->expects(static::once())->method('fail')->with('refund-id');

        ($this->subscriber)($this->event(['id' => 8612, 'status' => 'completed']));
    }

    /**
     * The sandbox answers `GET /refunds/{id}` with the bare refund object.
     */
    public function testUnwrappedApiResponseIsAccepted(): void
    {
        $this->apiResponse = ['id' => 8612, 'status' => 'completed'];
        $this->orderTransactionService->method('findRefundByExternalReference')->willReturn($this->refund());

        $this->refundProcessor->expects(static::once())->method('process');

        ($this->subscriber)($this->event(['id' => 8612]));
    }

    /**
     * A failed lookup must surface so the webhook answers 500 and Flutterwave
     * redelivers, rather than acknowledging an event that was never applied.
     */
    public function testApiErrorThrowsSoTheWebhookIsRetried(): void
    {
        $this->apiResponse = ['status' => 'error', 'message' => 'boom', 'data' => null];
        $this->orderTransactionService->method('findRefundByExternalReference')->willReturn($this->refund());

        $this->deduplicator->expects(static::never())->method('markProcessed');
        $this->expectException(FlutterwaveException::class);

        ($this->subscriber)($this->event(['id' => 8612, 'status' => 'completed']));
    }

    public function testDuplicateDeliveryIsIgnored(): void
    {
        $this->orderTransactionService->method('findRefundByExternalReference')->willReturn($this->refund());
        $this->deduplicator->method('isProcessed')->willReturn(true);

        $this->refundStateHandler->expects(static::never())->method('complete');

        ($this->subscriber)($this->event(['id' => 8612, 'status' => 'completed']));
    }

    /**
     * Independent of the dedup marks: a refund already final must never be
     * transitioned again, or the state machine throws.
     *
     * @return array<string, array{string}>
     */
    public static function finalStateProvider(): array
    {
        return [
            'completed' => [OrderTransactionCaptureRefundStates::STATE_COMPLETED],
            'failed' => [OrderTransactionCaptureRefundStates::STATE_FAILED],
            'cancelled' => [OrderTransactionCaptureRefundStates::STATE_CANCELLED],
        ];
    }

    #[DataProvider('finalStateProvider')]
    public function testAlreadyFinalRefundIsNotTransitionedAgain(string $state): void
    {
        $this->orderTransactionService->method('findRefundByExternalReference')->willReturn($this->refund($state));

        $this->refundStateHandler->expects(static::never())->method('complete');
        $this->refundStateHandler->expects(static::never())->method('fail');

        ($this->subscriber)($this->event(['id' => 8612, 'status' => 'completed']));
    }

    public function testUnknownRefundIsAcknowledgedWithoutAction(): void
    {
        // Raised from the Flutterwave dashboard or another integration.
        $this->orderTransactionService->method('findRefundByExternalReference')->willReturn(null);

        $this->refundStateHandler->expects(static::never())->method('complete');

        ($this->subscriber)($this->event(['id' => 8612, 'status' => 'completed']));
    }

    public function testMissingRefundIdIsIgnored(): void
    {
        $this->orderTransactionService->expects(static::never())->method('findRefundByExternalReference');

        ($this->subscriber)($this->event(['status' => 'completed']));
    }

    /**
     * Defensive data-integrity guard: a refund entity should always carry a
     * transactionCapture, but the association can legitimately come back
     * unloaded. Nothing downstream (RefundProcessor, the state handler) can act
     * without it, so the webhook is acknowledged and dropped rather than fatal.
     */
    public function testRefundWithoutATransactionCaptureIsIgnored(): void
    {
        $refund = new OrderTransactionCaptureRefundEntity();
        $refund->setId('refund-id');
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName('open');
        $refund->setStateMachineState($stateEntity);
        // transactionCapture deliberately left unset.

        $this->orderTransactionService->method('findRefundByExternalReference')->willReturn($refund);

        $this->refundProcessor->expects(static::never())->method('process');
        $this->refundStateHandler->expects(static::never())->method('fail');
        $this->deduplicator->expects(static::never())->method('markProcessed');

        ($this->subscriber)($this->event(['id' => 8612, 'status' => 'completed']));
    }
}
