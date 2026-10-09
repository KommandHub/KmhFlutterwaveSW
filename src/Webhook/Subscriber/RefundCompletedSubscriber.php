<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Webhook\Subscriber;

use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\RefundProcessor;
use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Exception\FlutterwaveException;
use Kommandhub\FlutterwaveSW\Logging\ConfigurableLogger;
use Kommandhub\FlutterwaveSW\Service\OrderTransactionService;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveConstants;
use Kommandhub\FlutterwaveSW\Webhook\Event\RefundCompletedEvent;
use Kommandhub\FlutterwaveSW\Webhook\Service\WebhookDeduplicator;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Payment\Cart\RefundPaymentTransactionStruct;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Completes a pending refund from a `refund.completed` webhook.
 *
 * The admin refund action creates the Shopware capture and refund in their
 * initial (pending) state and records the Flutterwave refund id on the refund's
 * `externalReference`. Flutterwave refunds are asynchronous — "refunds initiated
 * usually take between 3-15 working days to be completed" — so the local record
 * stays pending until Flutterwave confirms the outcome here.
 *
 * Correlation is by that stored refund id, which is why nothing else in the
 * payload needs to be trusted to find the right record.
 */
final readonly class RefundCompletedSubscriber
{
    public function __construct(
        private OrderTransactionService $orderTransactionService,
        private FlutterwaveClient $flutterwave,
        private OrderTransactionCaptureRefundStateHandler $refundStateHandler,
        private RefundProcessor $refundProcessor,
        private WebhookDeduplicator $deduplicator,
        private ConfigurableLogger $logger
    ) {
    }

    #[AsEventListener(RefundCompletedEvent::class)]
    public function __invoke(RefundCompletedEvent $event): void
    {
        $context = $event->getContext();
        $flutterwaveRefundId = $event->getRefundId();

        if ($flutterwaveRefundId === null) {
            $this->logger->warning('[Flutterwave] refund.completed webhook is missing the refund id.');

            return;
        }

        $refund = $this->orderTransactionService->findRefundByExternalReference($flutterwaveRefundId, $context);

        if ($refund === null) {
            // A refund raised outside this shop (the Flutterwave dashboard, or
            // another integration on the same account). Acknowledge and ignore.
            $this->logger->info('[Flutterwave] refund.completed webhook has no matching pending refund.', [
                'flutterwaveRefundId' => $flutterwaveRefundId,
            ]);

            return;
        }

        $transaction = $refund->getTransactionCapture()?->getTransaction();

        if ($transaction !== null) {
            $eventKey = $this->deduplicator->buildKey(RefundCompletedEvent::getWebhookName(), $flutterwaveRefundId);

            if ($this->deduplicator->isProcessed($transaction, $eventKey)) {
                $this->logger->info('[Flutterwave] refund.completed webhook already processed; ignoring redelivery.', [
                    'flutterwaveRefundId' => $flutterwaveRefundId,
                ]);

                return;
            }
        }

        // Independent of the dedup marks: a refund already in a final state must
        // not be transitioned again, or the state machine throws.
        $currentState = $refund->getStateMachineState()?->getTechnicalName();

        if (in_array($currentState, [
            OrderTransactionCaptureRefundStates::STATE_COMPLETED,
            OrderTransactionCaptureRefundStates::STATE_FAILED,
            OrderTransactionCaptureRefundStates::STATE_CANCELLED,
        ], true)) {
            $this->logger->info('[Flutterwave] refund is already final; ignoring webhook.', [
                'flutterwaveRefundId' => $flutterwaveRefundId,
                'state' => $currentState,
            ]);

            return;
        }

        $transactionCapture = $refund->getTransactionCapture();

        if ($transactionCapture === null) {
            $this->logger->warning('[Flutterwave] refund.completed webhook: refund has no associated transaction capture.', [
                'flutterwaveRefundId' => $flutterwaveRefundId,
                'refundId' => $refund->getId(),
            ]);

            return;
        }

        // The payload's own status is never trusted: `verif-hash` does not cover
        // the body, so a replayed delivery could claim any outcome. Ask
        // Flutterwave. A failed lookup throws, the webhook answers 500 and
        // Flutterwave redelivers later.
        $status = $this->fetchRefundStatus($flutterwaveRefundId, $transaction?->getOrder()?->getSalesChannelId());

        if (in_array($status, FlutterwaveConstants::REFUND_SUCCESS_STATUSES, true)) {
            $this->refundProcessor->process(
                // Shopware's signature is ($refundId, $orderTransactionId).
                new RefundPaymentTransactionStruct(
                    $refund->getId(),
                    $transactionCapture->getOrderTransactionId()
                ),
                $context
            );

            $this->logger->info('[Flutterwave] Refund completed via webhook.', [
                'flutterwaveRefundId' => $flutterwaveRefundId,
                'refundId' => $refund->getId(),
            ]);
        } elseif (in_array($status, FlutterwaveConstants::REFUND_FAILURE_STATUSES, true)) {
            $this->refundStateHandler->fail($refund->getId(), $context);

            $this->logger->warning('[Flutterwave] Refund failed via webhook.', [
                'flutterwaveRefundId' => $flutterwaveRefundId,
                'refundId' => $refund->getId(),
            ]);
        } else {
            // Still in flight. Leave it pending and do NOT mark the event
            // processed, so a later delivery with a final status is still acted
            // on.
            $this->logger->info('[Flutterwave] Refund webhook reports a non-final status; leaving it pending.', [
                'flutterwaveRefundId' => $flutterwaveRefundId,
                'status' => $status,
            ]);

            return;
        }

        if ($transaction !== null) {
            $this->deduplicator->markProcessed(
                $transaction,
                $this->deduplicator->buildKey(RefundCompletedEvent::getWebhookName(), $flutterwaveRefundId),
                $context
            );
        }
    }

    /**
     * @throws FlutterwaveException
     */
    private function fetchRefundStatus(string $flutterwaveRefundId, ?string $salesChannelId): ?string
    {
        $response = $this->flutterwave->refunds()->fetch($flutterwaveRefundId, $salesChannelId);

        if (($response['status'] ?? null) === 'error') {
            throw new FlutterwaveException(sprintf('Could not fetch Flutterwave refund %s.', $flutterwaveRefundId));
        }

        // The documented envelope is `{status, data: {...}}`; the sandbox answers
        // with the bare refund object. Accept both.
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $status = $data['status'] ?? null;

        return is_string($status) ? strtolower($status) : null;
    }
}
