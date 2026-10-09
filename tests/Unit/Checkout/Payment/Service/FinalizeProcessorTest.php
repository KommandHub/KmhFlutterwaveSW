<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit\Checkout\Payment\Service;

use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\FinalizeProcessor;
use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Client\Resource\Transaction as TransactionResource;
use Kommandhub\FlutterwaveSW\Logging\ConfigurableLogger;
use Kommandhub\FlutterwaveSW\Service\OrderTransactionService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\Request;

class FinalizeProcessorTest extends TestCase
{
    private OrderTransactionService|\PHPUnit\Framework\MockObject\MockObject $orderTransactionService;
    private FlutterwaveClient|\PHPUnit\Framework\MockObject\MockObject $flutterwave;
    private OrderTransactionStateHandler|\PHPUnit\Framework\MockObject\MockObject $transactionStateHandler;
    private ConfigurableLogger|\PHPUnit\Framework\MockObject\MockObject $logger;
    private FinalizeProcessor $processor;

    protected function setUp(): void
    {
        $this->orderTransactionService = $this->createMock(OrderTransactionService::class);
        $this->flutterwave = $this->createMock(FlutterwaveClient::class);
        $this->transactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->logger = $this->createMock(ConfigurableLogger::class);

        $this->processor = new FinalizeProcessor(
            $this->orderTransactionService,
            $this->flutterwave,
            $this->transactionStateHandler,
            $this->logger
        );
    }

    public function testProcessSuccess(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');
        $request = new Request(['status' => 'successful', 'transaction_id' => '12345']);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('USD');
        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);
        $orderTransaction->setAmount(new CalculatedPrice(100.0, 100.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);

        $transactionResource = $this->createMock(TransactionResource::class);
        $this->flutterwave->method('transactions')->willReturn($transactionResource);

        $transactionResource->expects(static::once())
            ->method('verify')
            ->with('12345', 'sales-channel-1')
            ->willReturn([
                'status' => 'success',
                'data' => [
                    'id' => 12345,
                    'status' => 'successful',
                    'amount' => 100.0,
                    'currency' => 'USD',
                    'tx_ref' => 'transaction-1',
                ],
            ]);

        $this->transactionStateHandler->expects(static::once())->method('paid');
        $this->orderTransactionService->expects(static::once())->method('update');

        $this->processor->process($request, $transactionStruct, $context);
    }

    public function testProcessCancelled(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');
        $request = new Request(['status' => 'cancelled']);

        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);

        $this->transactionStateHandler->expects(static::once())->method('cancel');

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Customer canceled the payment on Flutterwave.');

        $this->processor->process($request, $transactionStruct, $context);
    }

    public function testProcessMissingTransactionId(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');
        $request = new Request(['status' => 'successful']); // transaction_id missing

        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Flutterwave transaction ID is missing.');

        $this->processor->process($request, $transactionStruct, $context);
    }

    public function testProcessAmountMismatch(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');
        $request = new Request(['status' => 'successful', 'transaction_id' => '12345']);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('USD');
        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);
        $orderTransaction->setAmount(new CalculatedPrice(100.0, 100.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);

        $transactionResource = $this->createMock(TransactionResource::class);
        $this->flutterwave->method('transactions')->willReturn($transactionResource);

        $transactionResource->method('verify')->willReturn([
            'status' => 'success',
            'data' => [
                'amount' => 50.0, // Mismatch
                'currency' => 'USD',
                'tx_ref' => 'transaction-1',
            ],
        ]);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Flutterwave verification failed: Amount or currency mismatch.');

        $this->processor->process($request, $transactionStruct, $context);
    }

    public function testProcessPaymentFailed(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');
        $request = new Request(['status' => 'successful', 'transaction_id' => '12345']);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('USD');
        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);
        $orderTransaction->setAmount(new CalculatedPrice(100.0, 100.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);

        $transactionResource = $this->createMock(TransactionResource::class);
        $this->flutterwave->method('transactions')->willReturn($transactionResource);

        $transactionResource->method('verify')->willReturn([
            'status' => 'success',
            'data' => [
                'status' => 'failed',
                'amount' => 100.0,
                'currency' => 'USD',
                'tx_ref' => 'transaction-1',
            ],
        ]);

        $this->transactionStateHandler->expects(static::once())->method('fail');

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Flutterwave payment failed.');

        $this->processor->process($request, $transactionStruct, $context);
    }

    public function testDescribeErrorReturnsUnknown(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');
        $request = new Request(['status' => 'successful', 'transaction_id' => '12345']);

        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);

        $transactionResource = $this->createMock(TransactionResource::class);
        $this->flutterwave->method('transactions')->willReturn($transactionResource);

        $transactionResource->method('verify')->willReturn([
            'status' => 'error',
            // message missing
        ]);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Flutterwave verification failed: Unknown error');

        $this->processor->process($request, $transactionStruct, $context);
    }

    public function testProcessMissingCurrencyInResponse(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');
        $request = new Request(['status' => 'successful', 'transaction_id' => '12345']);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('USD');
        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);
        $orderTransaction->setAmount(new CalculatedPrice(100.0, 100.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);

        $transactionResource = $this->createMock(TransactionResource::class);
        $this->flutterwave->method('transactions')->willReturn($transactionResource);

        $transactionResource->method('verify')->willReturn([
            'status' => 'success',
            'data' => [
                'amount' => 100.0,
                'tx_ref' => 'transaction-1',
                // currency missing
            ],
        ]);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Flutterwave verification failed: Amount or currency mismatch.');

        $this->processor->process($request, $transactionStruct, $context);
    }

    public function testUpdateTransactionStateUnexpectedStatus(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');
        $request = new Request(['status' => 'successful', 'transaction_id' => '12345']);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('USD');
        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);
        $orderTransaction->setAmount(new CalculatedPrice(100.0, 100.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);

        $transactionResource = $this->createMock(TransactionResource::class);
        $this->flutterwave->method('transactions')->willReturn($transactionResource);

        $transactionResource->method('verify')->willReturn([
            'status' => 'success',
            'data' => [
                'status' => 'unexpected_status',
                'amount' => 100.0,
                'currency' => 'USD',
                'tx_ref' => 'transaction-1',
            ],
        ]);

        $this->transactionStateHandler->expects(static::once())->method('reopen');

        $this->processor->process($request, $transactionStruct, $context);
    }

    public function testProcessRejectsPaymentBelongingToAnotherTransaction(): void
    {
        $orderTransaction = $this->createOrderTransaction();
        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);
        $this->mockVerifyResponse([
            'id' => 12345,
            'status' => 'successful',
            'amount' => 100.0,
            'currency' => 'USD',
            'tx_ref' => 'some-other-transaction',
        ]);

        $this->transactionStateHandler->expects(static::never())->method('paid');
        $this->orderTransactionService->expects(static::never())->method('update');

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Flutterwave verification failed: Transaction reference mismatch.');

        $this->processor->process(
            new Request(['status' => 'successful', 'transaction_id' => '12345']),
            new PaymentTransactionStruct('transaction-1', 'http://return.url'),
            Context::createDefaultContext()
        );
    }

    public function testProcessSkipsPaidTransitionWhenAlreadyPaid(): void
    {
        $state = new StateMachineStateEntity();
        $state->setTechnicalName(OrderTransactionStates::STATE_PAID);
        $orderTransaction = $this->createOrderTransaction();
        $orderTransaction->setStateMachineState($state);
        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);
        $this->mockVerifyResponse([
            'id' => 12345,
            'status' => 'successful',
            'amount' => 100.0,
            'currency' => 'USD',
            'tx_ref' => 'transaction-1',
        ]);

        $this->transactionStateHandler->expects(static::never())->method('paid');

        $this->processor->process(
            new Request(['status' => 'successful', 'transaction_id' => '12345']),
            new PaymentTransactionStruct('transaction-1', 'http://return.url'),
            Context::createDefaultContext()
        );
    }

    private function createOrderTransaction(): OrderTransactionEntity
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('USD');
        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $order->setCurrency($currency);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);
        $orderTransaction->setAmount(new CalculatedPrice(100.0, 100.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        return $orderTransaction;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mockVerifyResponse(array $data): void
    {
        $transactionResource = $this->createMock(TransactionResource::class);
        $transactionResource->method('verify')->willReturn(['status' => 'success', 'data' => $data]);
        $this->flutterwave->method('transactions')->willReturn($transactionResource);
    }
}
