<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit\Checkout\Payment\Service;

use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\PaymentProcessor;
use Kommandhub\FlutterwaveSW\Checkout\Payment\Struct\PaymentPayload;
use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Client\Resource\Transaction as TransactionResource;
use Kommandhub\FlutterwaveSW\Logging\ConfigurableLogger;
use Kommandhub\FlutterwaveSW\Service\OrderTransactionService;
use Kommandhub\FlutterwaveSW\Service\PayloadBuilder;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveConstants;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PaymentProcessorTest extends TestCase
{
    private OrderTransactionService|\PHPUnit\Framework\MockObject\MockObject $orderTransactionService;
    private FlutterwaveClient|\PHPUnit\Framework\MockObject\MockObject $flutterwave;
    private PayloadBuilder|\PHPUnit\Framework\MockObject\MockObject $payloadBuilder;
    private ConfigurableLogger|\PHPUnit\Framework\MockObject\MockObject $logger;
    private UrlGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject $urlGenerator;
    private PaymentProcessor $processor;

    protected function setUp(): void
    {
        $this->orderTransactionService = $this->createMock(OrderTransactionService::class);
        $this->flutterwave = $this->createMock(FlutterwaveClient::class);
        $this->payloadBuilder = $this->createMock(PayloadBuilder::class);
        $this->logger = $this->createMock(ConfigurableLogger::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params): string => sprintf('http://shop/flutterwave/return/%s/%s', $params['orderTransactionId'], $params['nonce'])
        );

        $this->processor = new PaymentProcessor(
            $this->orderTransactionService,
            $this->flutterwave,
            $this->payloadBuilder,
            $this->logger,
            $this->urlGenerator
        );
    }

    public function testProcessSuccess(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');

        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);

        $this->orderTransactionService->expects(static::once())
            ->method('getOrderTransaction')
            ->with('transaction-1', $context)
            ->willReturn($orderTransaction);

        $payload = new PaymentPayload(100.0, 'USD', 'REF-1', 'http://return.url', 'test@example.com');
        // Shopware's finalize URL is stored server-side with a nonce...
        $storedNonce = null;
        $this->orderTransactionService->expects(static::once())
            ->method('update')
            ->with(static::callback(static function (array $payload) use (&$storedNonce): bool {
                $return = $payload[0]['customFields'][FlutterwaveConstants::FIELD_RETURN];
                $storedNonce = $return['nonce'];

                return $payload[0]['id'] === 'transaction-1'
                    && $return['url'] === 'http://return.url'
                    && strlen($return['nonce']) === 32;
            }));

        // ...and Flutterwave is given the clean plugin route carrying that nonce.
        $this->payloadBuilder->expects(static::once())
            ->method('build')
            ->with($orderTransaction, static::callback(static function (PaymentTransactionStruct $struct) use (&$storedNonce): bool {
                return $struct->getReturnUrl() === 'http://shop/flutterwave/return/transaction-1/' . $storedNonce;
            }))
            ->willReturn($payload);

        $transactionResource = $this->createMock(TransactionResource::class);
        $this->flutterwave->method('transactions')->willReturn($transactionResource);

        $transactionResource->expects(static::once())
            ->method('initialize')
            ->with($payload->toArray(), 'sales-channel-1')
            ->willReturn([
                'status' => 'success',
                'data' => ['link' => 'https://flutterwave.com/pay/xyz'],
            ]);

        $response = $this->processor->process($transactionStruct, $context);

        static::assertSame('https://flutterwave.com/pay/xyz', $response->getLink());
    }

    public function testProcessFailsWhenSalesChannelMissing(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);

        // We need to avoid build() being called because it would fail if we didn't mock it,
        // and it fails to mock because PaymentPayload is final.
        // But process() calls build() before checking salesChannelId? No, it doesn't.
        // Wait, let's look at the code.

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Sales channel ID is missing.');

        $this->processor->process($transactionStruct, $context);
    }

    public function testProcessFailsWhenFlutterwaveReturnsError(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');

        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);
        $this->payloadBuilder->method('build')->willReturn(new PaymentPayload(100.0, 'USD', 'REF-1', 'http://return.url', 'test@example.com'));

        $transactionResource = $this->createMock(TransactionResource::class);
        $this->flutterwave->method('transactions')->willReturn($transactionResource);

        $transactionResource->method('initialize')->willReturn([
            'status' => 'error',
            'message' => 'Invalid amount',
        ]);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Failed to initialize Flutterwave payment: Invalid amount');

        $this->processor->process($transactionStruct, $context);
    }

    public function testProcessFailsWhenExceptionThrown(): void
    {
        $context = Context::createDefaultContext();
        $transactionStruct = new PaymentTransactionStruct('transaction-1', 'http://return.url');

        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);

        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);
        $this->payloadBuilder->method('build')->willThrowException(new \Exception('DB Error'));

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('DB Error');

        $this->processor->process($transactionStruct, $context);
    }

    /**
     * Without Shopware's finalize URL the customer would be stranded on
     * Flutterwave after paying, so this fails before anything is initialized.
     */
    public function testProcessFailsWhenReturnUrlMissing(): void
    {
        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-1');
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-1');
        $orderTransaction->setOrder($order);
        $this->orderTransactionService->method('getOrderTransaction')->willReturn($orderTransaction);

        $this->orderTransactionService->expects(static::never())->method('update');
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Return URL is missing for the payment transaction.');

        $this->processor->process(new PaymentTransactionStruct('transaction-1'), Context::createDefaultContext());
    }
}
