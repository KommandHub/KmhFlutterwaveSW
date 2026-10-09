<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit\Administration\Controller;

use Kommandhub\FlutterwaveSW\Administration\Controller\RefundController;
use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\FlutterwaveRefundLedger;
use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\RefundAmountCalculator;
use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\RefundEligibilityResolver;
use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\RefundProcessor;
use Kommandhub\FlutterwaveSW\Checkout\Payment\Struct\RefundContext;
use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Client\Resource\Transaction;
use Kommandhub\FlutterwaveSW\Logging\ConfigurableLogger;
use Kommandhub\FlutterwaveSW\Service\OrderTransactionService;
use Kommandhub\FlutterwaveSW\Setting\Service\Config;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveCurrencyHelper;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveTransactionReader;
use Kommandhub\FlutterwaveSW\Util\OrderCurrencyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\RefundPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(RefundController::class)]
#[UsesClass(FlutterwaveCurrencyHelper::class)]
#[UsesClass(OrderCurrencyResolver::class)]
#[UsesClass(FlutterwaveTransactionReader::class)]
#[UsesClass(RefundEligibilityResolver::class)]
#[UsesClass(RefundAmountCalculator::class)]
#[UsesClass(FlutterwaveRefundLedger::class)]
#[UsesClass(RefundContext::class)]
class RefundControllerTest extends TestCase
{
    /**
     * A permissive minimum (1.0 major) so amount-boundary tests exercise the
     * remaining-balance logic rather than tripping the per-currency floor.
     */
    private function allowLowMinimum(): void
    {
        $this->config->method('get')
            ->with('minimumRefundAmount', null, 'sales-channel-id')
            ->willReturn(1.0);
    }

    private FlutterwaveClient&MockObject $flutterwave;
    private Transaction&MockObject $transactionResource;
    private OrderTransactionService&MockObject $orderTransactionService;
    private Config&MockObject $config;
    private ConfigurableLogger&MockObject $logger;
    private RefundProcessor&MockObject $refundProcessor;

    protected function setUp(): void
    {
        $this->transactionResource = $this->createMock(Transaction::class);
        $this->flutterwave = $this->createMock(FlutterwaveClient::class);
        $this->flutterwave->method('transactions')->willReturn($this->transactionResource);
        $this->transactionResource->method('verify')->willReturn(['status' => 'success', 'data' => ['flw_ref' => 'FLW-OURS']]);
        $this->orderTransactionService = $this->createMock(OrderTransactionService::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(ConfigurableLogger::class);
        $this->refundProcessor = $this->createMock(RefundProcessor::class);
    }

    /**
     * Builds the controller under test with real (not mocked) collaborator
     * services, wired to the same doubles (`$this->flutterwave`,
     * `$this->config`, ...) the individual test cases configure.
     *
     * This keeps the controller tests exercising the full refund workflow
     * end to end — exactly as before the controller's business logic was
     * extracted into `RefundEligibilityResolver`, `RefundAmountCalculator`
     * and `FlutterwaveRefundLedger` — rather than turning every test into a
     * shallow interaction test against mocked collaborators. The extracted
     * services also get their own focused unit tests.
     */
    private function controller(): RefundController
    {
        return new RefundController(
            $this->flutterwave,
            $this->orderTransactionService,
            new RefundEligibilityResolver($this->config),
            new FlutterwaveRefundLedger($this->flutterwave),
            new RefundAmountCalculator(),
            $this->refundProcessor,
            $this->logger
        );
    }

    private function context(): Context
    {
        return Context::createDefaultContext();
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function transaction(
        string $state = OrderTransactionStates::STATE_PAID,
        array $customFields = ['flutterwave_transaction_id' => '12345', 'flutterwave_amount_charged' => 100.0],
        ?string $currency = 'NGN'
    ): OrderTransactionEntity {
        $transaction = new OrderTransactionEntity();
        $transaction->setId('order-transaction-id');
        $transaction->setCustomFields($customFields);
        $transaction->setAmount(new CalculatedPrice(100.0, 100.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setId('state-id');
        $stateEntity->setTechnicalName($state);
        $transaction->setStateMachineState($stateEntity);

        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-id');

        if ($currency !== null) {
            $currencyEntity = new CurrencyEntity();
            $currencyEntity->setId('currency-id');
            $currencyEntity->setIsoCode($currency);
            $order->setCurrency($currencyEntity);
        }

        $transaction->setOrder($order);

        return $transaction;
    }

    private function request(array $body): Request
    {
        return new Request([], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        return json_decode((string)$response->getContent(), true);
    }

    // --- guards -------------------------------------------------------------

    public function testRefundRequiresOrderTransactionId(): void
    {
        $response = $this->controller()->refund($this->request([]), $this->context());

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('Order transaction id is required', $this->decode($response)['error']);
    }

    public function testRefundReturnsErrorWhenTransactionNotFound(): void
    {
        $this->orderTransactionService->method('getForRefund')->willThrowException(new \InvalidArgumentException());

        $response = $this->controller()->refund($this->request(['orderTransactionId' => 'x']), $this->context());

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('not found', $this->decode($response)['error']);
    }

    public function testRefundReturnsErrorWhenFeatureDisabled(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->with('refundEnabled', 'sales-channel-id')->willReturn(false);

        $response = $this->controller()->refund($this->request(['orderTransactionId' => 'order-transaction-id']), $this->context());

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('disabled', $this->decode($response)['error']);
    }

    public function testRefundRejectsNonRefundableState(): void
    {
        $this->orderTransactionService->method('getForRefund')
            ->willReturn($this->transaction(OrderTransactionStates::STATE_OPEN));
        $this->config->method('getBool')->willReturn(true);

        $response = $this->controller()->refund($this->request(['orderTransactionId' => 'order-transaction-id']), $this->context());

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('not in a refundable state', $this->decode($response)['error']);
    }

    public function testRefundRequiresFlutterwaveTransactionId(): void
    {
        $this->orderTransactionService->method('getForRefund')
            ->willReturn($this->transaction(customFields: ['flutterwave_amount_charged' => 100.0]));
        $this->config->method('getBool')->willReturn(true);

        $response = $this->controller()->refund($this->request(['orderTransactionId' => 'order-transaction-id']), $this->context());

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('no Flutterwave transaction id', $this->decode($response)['error']);
    }

    public function testRefundRejectsNonNumericTransactionIdInsteadOfSendingIt(): void
    {
        // Flutterwave transaction ids are numeric; the Shopware order_transaction
        // id is a hex UUID. If a UUID ever lands in the custom field it must be
        // rejected, never used as the refund target — otherwise the refund would
        // POST to /transactions/{shopware-uuid}/refund.
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction(customFields: [
            'flutterwave_transaction_id' => '019f6531ce1c73149ce304caf2fe2ecf',
            'flutterwave_amount_charged' => 100.0,
        ]));
        $this->config->method('getBool')->willReturn(true);
        $this->transactionResource->expects(static::never())->method('refund');

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '10']),
            $this->context()
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('no Flutterwave transaction id', $this->decode($response)['error']);
    }

    public function testRefundFailsClosedWhenCurrencyUnresolved(): void
    {
        $this->orderTransactionService->method('getForRefund')
            ->willReturn($this->transaction(currency: null));
        $this->config->method('getBool')->willReturn(true);

        $response = $this->controller()->refund($this->request(['orderTransactionId' => 'order-transaction-id']), $this->context());

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('resolve the order currency', $this->decode($response)['error']);
    }

    public function testRefundRejectsNonPositiveAmount(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '-5']),
            $this->context()
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('positive number', $this->decode($response)['error']);
    }

    // --- amount validation --------------------------------------------------

    public function testRefundRejectsAmountBelowMinimum(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->config->method('get')->with('minimumRefundAmount', null, 'sales-channel-id')->willReturn(100.0);
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => []]);

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '10']),
            $this->context()
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('at least', $this->decode($response)['error']);
    }

    public function testRefundRejectsAmountAboveRemainingBalance(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        // 40 already refunded, charged 100 => remaining 60. Requesting 70 must fail.
        $this->transactionResource->method('refunds')->willReturn([
            'status' => 'success',
            'data' => [['flw_ref' => 'FLW-OURS', 'status' => 'completed', 'amount_refunded' => 40]],
        ]);

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '70']),
            $this->context()
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('exceeds the refundable balance', $this->decode($response)['error']);
    }

    public function testFailedRefundsDoNotConsumeBalance(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        // A failed refund frees its amount, so the full 100 is refundable again.
        // Uses Flutterwave's real `amount_refunded` field.
        $this->transactionResource->method('refunds')->willReturn([
            'status' => 'success',
            'data' => [['flw_ref' => 'FLW-OURS', 'status' => 'failed', 'amount_refunded' => 100]],
        ]);
        $this->transactionResource->expects(static::once())
            ->method('refund')
            ->with('12345', 100.0, null, 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => ['id' => 1]]);

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '100']),
            $this->context()
        );

        static::assertSame(200, $response->getStatusCode());
    }

    public function testSuccessfulStatusRefundsConsumeBalance(): void
    {
        // Flutterwave uses "successful" alongside "completed". An allowlist that
        // only knew "completed" would miss this and authorise an over-refund;
        // the denylist counts it. 60 already refunded, charged 100 => remaining
        // 40, so requesting 50 must be rejected.
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        $this->transactionResource->method('refunds')->willReturn([
            'status' => 'success',
            'data' => [['flw_ref' => 'FLW-OURS', 'status' => 'successful', 'amount_refunded' => 60]],
        ]);
        $this->transactionResource->expects(static::never())->method('refund');

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '50']),
            $this->context()
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('exceeds the refundable balance', $this->decode($response)['error']);
    }

    // --- happy paths --------------------------------------------------------

    public function testFullRefundWithoutAmountRefundsRemainingBalance(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        $this->transactionResource->method('refunds')->willReturn([
            'status' => 'success',
            'data' => [['flw_ref' => 'FLW-OURS', 'status' => 'completed', 'amount_refunded' => 30]],
        ]);
        // Remaining = 100 - 30 = 70, sent in major units.
        $this->transactionResource->expects(static::once())
            ->method('refund')
            ->with('12345', 70.0, null, 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => ['id' => 9]]);

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id']),
            $this->context()
        );

        static::assertSame(200, $response->getStatusCode());
    }

    public function testPartialRefundSendsCommentsAndMajorAmount(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => []]);
        $this->transactionResource->expects(static::once())
            ->method('refund')
            ->with('12345', 25.5, 'duplicate charge', 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => ['id' => 7]]);

        $response = $this->controller()->refund(
            $this->request([
                'orderTransactionId' => 'order-transaction-id',
                'amount' => '25.5',
                'comments' => '  duplicate charge  ',
            ]),
            $this->context()
        );

        static::assertSame(200, $response->getStatusCode());
    }

    /**
     * The sandbox (and sometimes live) settles a refund synchronously. It must
     * be completed locally right away, not left pending on a webhook.
     */
    public function testRefundReportedCompletedIsCompletedLocally(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->orderTransactionService->method('createRefund')->willReturn('local-refund-id');
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => []]);
        $this->transactionResource->method('refund')
            ->willReturn(['status' => 'success', 'data' => ['id' => 7, 'status' => 'completed']]);

        $this->refundProcessor->expects(static::once())
            ->method('process')
            ->with(static::callback(static fn (RefundPaymentTransactionStruct $struct): bool => $struct->getRefundId() === 'local-refund-id'
                && $struct->getOrderTransactionId() === 'order-transaction-id'));

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '10']),
            $this->context()
        );

        static::assertSame(200, $response->getStatusCode());
    }

    public function testPendingRefundIsLeftForTheWebhook(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->orderTransactionService->method('createRefund')->willReturn('local-refund-id');
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => []]);
        $this->transactionResource->method('refund')
            ->willReturn(['status' => 'success', 'data' => ['id' => 7, 'status' => 'pending']]);

        $this->refundProcessor->expects(static::never())->method('process');

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '10']),
            $this->context()
        );

        static::assertSame(200, $response->getStatusCode());
    }

    public function testRefundReturnsErrorWhenFlutterwaveRejects(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => []]);
        $this->transactionResource->method('refund')->willReturn([
            'status' => 'error',
            'message' => 'Transaction not refundable',
        ]);

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '10']),
            $this->context()
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('Transaction not refundable', $this->decode($response)['error']);
    }

    public function testRefundWrapsClientException(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => []]);
        $this->transactionResource->method('refund')->willThrowException(new \RuntimeException('network down'));

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '10']),
            $this->context()
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('network down', $this->decode($response)['error']);
    }

    public function testRefundReturnsErrorWhenBalanceLookupFails(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->transactionResource->method('refunds')->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '10']),
            $this->context()
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertStringContainsString('verify the refundable balance', $this->decode($response)['error']);
    }

    public function testMinimumFallsBackToPerCurrencyConstant(): void
    {
        // No configured minimum → NGN constant (100). A 100 refund (== charged,
        // == remaining) is exactly at the floor and succeeds.
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->config->method('get')->willReturn(null);
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => []]);
        $this->transactionResource->expects(static::once())
            ->method('refund')
            ->with('12345', 100.0, null, 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => ['id' => 1]]);

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '100']),
            $this->context()
        );

        static::assertSame(200, $response->getStatusCode());
    }

    public function testChargedAmountFallsBackToTransactionTotal(): void
    {
        // No amount_charged custom field → the refundable base is the Shopware
        // transaction total (100), so a full refund still works.
        $this->orderTransactionService->method('getForRefund')
            ->willReturn($this->transaction(customFields: ['flutterwave_transaction_id' => '12345']));
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => []]);
        $this->transactionResource->expects(static::once())
            ->method('refund')
            ->with('12345', 100.0, null, 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => ['id' => 1]]);

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id']),
            $this->context()
        );

        static::assertSame(200, $response->getStatusCode());
    }

    public function testMalformedRefundEntriesAreIgnoredInBalance(): void
    {
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        // A non-array entry and a non-numeric amount must not break the sum.
        $this->transactionResource->method('refunds')->willReturn([
            'status' => 'success',
            'data' => [
                'not-an-array',
                ['flw_ref' => 'FLW-OURS', 'status' => 'completed', 'amount_refunded' => 'x'],
                ['flw_ref' => 'FLW-OURS', 'status' => 'completed', 'amount_refunded' => 20],
            ],
        ]);
        $this->transactionResource->expects(static::once())
            ->method('refund')
            ->with('12345', 80.0, null, 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => ['id' => 1]]);

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id']),
            $this->context()
        );

        static::assertSame(200, $response->getStatusCode());
    }

    // --- over-refund guard --------------------------------------------------

    public function testForeignTransactionRefundsDoNotShrinkRefundableBalance(): void
    {
        // A refund belonging to another transaction (different flw_ref) must never
        // inflate this transaction's already-refunded total and shrink its
        // refundable balance. The ledger filters the account-wide list by flw_ref.
        $this->orderTransactionService->method('getForRefund')->willReturn($this->transaction());
        $this->config->method('getBool')->willReturn(true);
        $this->allowLowMinimum();
        $this->transactionResource->method('refunds')->willReturn(['status' => 'success', 'data' => [
            ['flw_ref' => 'FLW-OTHER', 'status' => 'completed', 'amount_refunded' => 100],
        ]]);
        // Foreign refund ignored => full 100 still refundable.
        $this->transactionResource->expects(static::once())
            ->method('refund')
            ->with('12345', 100.0, null, 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => ['id' => 1]]);

        $response = $this->controller()->refund(
            $this->request(['orderTransactionId' => 'order-transaction-id', 'amount' => '100']),
            $this->context()
        );

        static::assertSame(200, $response->getStatusCode());
    }
}
