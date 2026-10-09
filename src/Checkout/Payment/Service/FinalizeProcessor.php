<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Checkout\Payment\Service;

use Kommandhub\FlutterwaveSW\Service\OrderTransactionService;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveCurrencyHelper;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveConstants;
use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Logging\ConfigurableLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;

readonly class FinalizeProcessor
{
    private const SETTLED_STATES = [
        OrderTransactionStates::STATE_PAID,
        OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
        OrderTransactionStates::STATE_REFUNDED,
    ];

    public function __construct(
        private OrderTransactionService $orderTransactionService,
        private FlutterwaveClient $flutterwave,
        private OrderTransactionStateHandler $transactionStateHandler,
        private ConfigurableLogger $logger
    ) {
    }

    /**
     * Processes the finalization of a Flutterwave payment.
     *
     * @throws PaymentException
     */
    public function process(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $orderTransaction = $this->orderTransactionService->getOrderTransaction($transaction->getOrderTransactionId(), $context);
        $order = $orderTransaction->getOrder();
        $salesChannelId = $order?->getSalesChannelId();

        $this->logger->info('[Flutterwave] Finalize started.', $this->logContext($salesChannelId, [
            'orderTransactionId' => $transaction->getOrderTransactionId(),
            'queryParams' => $request->query->all(),
        ]));

        if ($salesChannelId === null) {
            throw PaymentException::asyncFinalizeInterrupted($orderTransaction->getId(), 'Sales channel ID is missing.'); // @codeCoverageIgnore
        }

        $status = $request->query->get('status');
        $transactionId = $request->query->get('transaction_id');

        if ($status === 'cancelled') {
            $this->logger->info('[Flutterwave] Payment cancelled by customer.', $this->logContext($salesChannelId, [
                'orderTransactionId' => $orderTransaction->getId(),
            ]));
            $this->transactionStateHandler->cancel($orderTransaction->getId(), $context);
            throw PaymentException::customerCanceled($orderTransaction->getId(), 'Customer canceled the payment on Flutterwave.');
        }

        if ($transactionId === null) {
            throw PaymentException::asyncFinalizeInterrupted($orderTransaction->getId(), 'Flutterwave transaction ID is missing.');
        }

        try {
            $response = $this->flutterwave->transactions()->verify((string)$transactionId, $salesChannelId);

            $this->logger->debug('[Flutterwave] Verify response received.', $this->logContext($salesChannelId, [
                'response' => $response,
            ]));

            if (($response['status'] ?? null) !== 'success') {
                throw new \RuntimeException('Flutterwave verification failed: ' . $this->describeError($response));
            }

            if (!is_array($response['data'] ?? null)) {
                throw new \RuntimeException('Flutterwave verification failed: response contained no transaction data.'); // @codeCoverageIgnore
            }

            /** @var array<string, mixed> $data */
            $data = $response['data'];

            $this->validateTransactionData($orderTransaction, $data, $salesChannelId);

            $this->saveTransactionData($orderTransaction, $data, (string)$transactionId, $context);

            $status = $data['status'] ?? '';
            $this->updateTransactionState($orderTransaction, is_string($status) ? $status : '', $context, $salesChannelId);
        } catch (\Exception $e) {
            $this->logger->error('[Flutterwave] Finalize failed.', $this->logContext($salesChannelId, [
                'orderTransactionId' => $orderTransaction->getId(),
                'exception' => $e,
            ]));

            throw PaymentException::asyncFinalizeInterrupted($orderTransaction->getId(), $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function describeError(array $response): string
    {
        $message = $response['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'Unknown error';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateTransactionData(OrderTransactionEntity $orderTransaction, array $data, ?string $salesChannelId): void
    {
        // Bind the verified payment to THIS order transaction. Amount and currency
        // alone are not enough: the customer controls `transaction_id` on the
        // return URL, so without this check any earlier successful payment of
        // the same total could be replayed to settle a different order.
        if (($data['tx_ref'] ?? null) !== $orderTransaction->getId()) {
            $this->logger->error('[Flutterwave] Transaction reference mismatch; refusing to mark the order paid.', $this->logContext($salesChannelId, [
                'orderTransactionId' => $orderTransaction->getId(),
                'receivedReference' => $data['tx_ref'] ?? null,
            ]));

            throw new \RuntimeException('Flutterwave verification failed: Transaction reference mismatch.');
        }

        $expectedAmount = $orderTransaction->getAmount()->getTotalPrice();
        $expectedCurrency = $orderTransaction->getOrder()?->getCurrency()?->getIsoCode();

        $actualAmount = 0.0;

        if (isset($data['amount']) && is_numeric($data['amount'])) {
            $actualAmount = (float)$data['amount'];
        }
        $actualCurrency = isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : null;

        if ($actualCurrency === null) {
            $this->logger->error('[Flutterwave] Currency missing in verification response.', $this->logContext($salesChannelId, [
                'orderTransactionId' => $orderTransaction->getId(),
            ]));

            throw new \RuntimeException('Flutterwave verification failed: Amount or currency mismatch.');
        }

        if ($actualCurrency !== $expectedCurrency || !FlutterwaveCurrencyHelper::amountsMatch($actualAmount, $expectedAmount, $actualCurrency)) {
            $this->logger->error('[Flutterwave] Amount or currency mismatch; refusing to mark the order paid.', $this->logContext($salesChannelId, [
                'orderTransactionId' => $orderTransaction->getId(),
                'expectedAmount' => $expectedAmount,
                'receivedAmount' => $actualAmount,
                'expectedCurrency' => $expectedCurrency,
                'receivedCurrency' => $actualCurrency,
            ]));

            throw new \RuntimeException('Flutterwave verification failed: Amount or currency mismatch.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveTransactionData(OrderTransactionEntity $orderTransaction, array $data, string $transactionId, Context $context): void
    {
        $customFields = $orderTransaction->getCustomFields() ?? [];
        $customFields = array_merge($customFields, [
            FlutterwaveConstants::FIELD_REFERENCE => $data['tx_ref'] ?? null,
            FlutterwaveConstants::FIELD_TRANSACTION_ID => is_numeric($data['id'] ?? null) ? (string)$data['id'] : $transactionId,
            FlutterwaveConstants::FIELD_PAYMENT_TYPE => $data['payment_type'] ?? null,
            FlutterwaveConstants::FIELD_TRANSACTION_FEE => $data['app_fee'] ?? null,
            FlutterwaveConstants::FIELD_AMOUNT_CHARGED => $data['amount'] ?? ($data['charged_amount'] ?? null),
            FlutterwaveConstants::FIELD_AMOUNT_SETTLED => $data['amount_settled'] ?? null,
            FlutterwaveConstants::FIELD_CURRENCY => $data['currency'] ?? null,
            FlutterwaveConstants::FIELD_VERIFIED_AT => (new \DateTime())->format(\DateTimeInterface::ATOM),
            FlutterwaveConstants::FIELD_CUSTOMER => $data['customer'] ?? null,
        ]);

        $this->orderTransactionService->update([
            [
                'id' => $orderTransaction->getId(),
                'customFields' => $customFields,
            ],
        ], $context);
    }

    private function updateTransactionState(OrderTransactionEntity $orderTransaction, string $status, Context $context, ?string $salesChannelId): void
    {
        if ($status === 'successful') {
            // The webhook and the customer's redirect race; whichever lands second
            // finds the transaction already settled. Shopware has no paid -> paid
            // transition, so re-applying it would throw and show the customer a
            // failure page for a payment that succeeded.
            if (in_array($orderTransaction->getStateMachineState()?->getTechnicalName(), self::SETTLED_STATES, true)) {
                $this->logger->info('[Flutterwave] Payment already settled; nothing to do.', $this->logContext($salesChannelId, [
                    'orderTransactionId' => $orderTransaction->getId(),
                ]));

                return;
            }

            $this->logger->info('[Flutterwave] Payment successful.', $this->logContext($salesChannelId, [
                'orderTransactionId' => $orderTransaction->getId(),
            ]));
            $this->transactionStateHandler->paid($orderTransaction->getId(), $context);
        } elseif ($status === 'failed') {
            $this->logger->warning('[Flutterwave] Payment failed.', $this->logContext($salesChannelId, [
                'orderTransactionId' => $orderTransaction->getId(),
            ]));
            $this->transactionStateHandler->fail($orderTransaction->getId(), $context);
            throw new \RuntimeException('Flutterwave payment failed.');
        } else {
            $this->logger->warning('[Flutterwave] Unexpected payment status; reopening the transaction.', $this->logContext($salesChannelId, [
                'orderTransactionId' => $orderTransaction->getId(),
                'status' => $status,
            ]));
            $this->transactionStateHandler->reopen($orderTransaction->getId(), $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function logContext(?string $salesChannelId, array $context = []): array
    {
        return [ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId] + $context;
    }
}
