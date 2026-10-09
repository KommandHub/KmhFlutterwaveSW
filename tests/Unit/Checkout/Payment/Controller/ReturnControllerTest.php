<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit\Checkout\Payment\Controller;

use Kommandhub\FlutterwaveSW\Checkout\Payment\Controller\ReturnController;
use Kommandhub\FlutterwaveSW\Service\OrderTransactionService;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveConstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ReturnController::class)]
class ReturnControllerTest extends TestCase
{
    private const TRANSACTION_ID = '01a12008ac4f724dbc56ad129d29b759';
    private const NONCE = 'abcdefghijklmnopqrstuvwxyz012345';
    private const FINALIZE_URL = 'http://shop/payment/finalize-transaction?_sw_payment_token=TOKEN';

    private OrderTransactionService&MockObject $orderTransactionService;
    private ReturnController $controller;

    protected function setUp(): void
    {
        $this->orderTransactionService = $this->createMock(OrderTransactionService::class);
        $this->controller = new ReturnController($this->orderTransactionService);

        $transaction = new OrderTransactionEntity();
        $transaction->setId(self::TRANSACTION_ID);
        $transaction->setCustomFields([
            FlutterwaveConstants::FIELD_RETURN => ['nonce' => self::NONCE, 'url' => self::FINALIZE_URL],
        ]);
        $this->orderTransactionService->method('getOrderTransaction')->willReturn($transaction);
    }

    public function testHostedCheckoutCallbackIsForwardedWithTheToken(): void
    {
        $response = $this->controller->return(
            self::TRANSACTION_ID,
            self::NONCE,
            new Request(['status' => 'successful', 'tx_ref' => self::TRANSACTION_ID, 'transaction_id' => '10541416']),
            Context::createDefaultContext()
        );

        static::assertSame(self::FINALIZE_URL . '&status=successful&transaction_id=10541416', $response->getTargetUrl());
    }

    /**
     * Flutterwave's card 3DS flow replaces the query string with
     * `?response={json}` — the shape that used to strand customers.
     */
    public function testThreeDsResponseJsonIsTranslated(): void
    {
        $response = $this->controller->return(
            self::TRANSACTION_ID,
            self::NONCE,
            new Request(['response' => json_encode(['id' => 10541416, 'status' => 'successful', 'txRef' => self::TRANSACTION_ID])]),
            Context::createDefaultContext()
        );

        static::assertSame(self::FINALIZE_URL . '&status=successful&transaction_id=10541416', $response->getTargetUrl());
    }

    public function testCancelledCallbackIsForwarded(): void
    {
        $response = $this->controller->return(
            self::TRANSACTION_ID,
            self::NONCE,
            new Request(['status' => 'cancelled', 'tx_ref' => self::TRANSACTION_ID]),
            Context::createDefaultContext()
        );

        static::assertSame(self::FINALIZE_URL . '&status=cancelled', $response->getTargetUrl());
    }

    /**
     * Without the nonce, anyone who learned a transaction id could drive
     * finalize — e.g. cancel someone else's pending payment.
     */
    public function testWrongNonceIsRejected(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller->return(self::TRANSACTION_ID, 'wrong', new Request(['status' => 'cancelled']), Context::createDefaultContext());
    }

    public function testInvalidTransactionIdIsRejected(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller->return('not-a-uuid', self::NONCE, new Request(), Context::createDefaultContext());
    }

    public function testUnknownTransactionIsRejected(): void
    {
        $service = $this->createMock(OrderTransactionService::class);
        $service->method('getOrderTransaction')->willThrowException(new \InvalidArgumentException());

        $this->expectException(NotFoundHttpException::class);

        (new ReturnController($service))->return(self::TRANSACTION_ID, self::NONCE, new Request(), Context::createDefaultContext());
    }
}
