<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Administration\Controller;

use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\FlutterwaveRefundLedgerInterface;
use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\RefundAmountCalculator;
use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\RefundEligibilityResolver;
use Kommandhub\FlutterwaveSW\Checkout\Payment\Service\RefundProcessor;
use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Exception\RefundValidationException;
use Kommandhub\FlutterwaveSW\Logging\ConfigurableLogger;
use Kommandhub\FlutterwaveSW\Service\OrderTransactionService;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveConstants;
use Shopware\Core\Checkout\Payment\Cart\RefundPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin refund endpoint for Flutterwave.
 *
 * Deliberately kept to HTTP concerns only — reading the request, calling
 * collaborator services in order, and translating their results/exceptions
 * into a {@see JsonResponse}. All refund business logic lives in dedicated
 * services this controller composes:
 *
 *  - {@see RefundEligibilityResolver} decides whether the transaction may be
 *    refunded at all (feature flag, transaction state, Flutterwave
 *    transaction id, order currency) and resolves the static facts
 *    (`RefundContext`) the rest of the flow needs.
 *  - {@see FlutterwaveRefundLedgerInterface} reads Flutterwave's own refund
 *    records for a transaction — the authoritative source for "how much has
 *    already been refunded", since a refund can be raised outside this shop.
 *  - {@see RefundAmountCalculator} validates the requested (or implied "full
 *    remaining balance") amount against that ledger and resolves the exact
 *    amount to send to Flutterwave.
 *  - {@see OrderTransactionService} owns the local Shopware side: creating
 *    the pending capture/refund entities and, later (via the
 *    `refund.completed` webhook), completing them.
 *
 * ## The refund workflow, end to end
 *
 * 1. **Initiation** — an admin submits an amount (or none, for "refund
 *    everything remaining") against a Shopware order transaction. This
 *    controller resolves eligibility, computes and validates the amount, and
 *    calls `POST /transactions/{id}/refund` on Flutterwave.
 * 2. **Acknowledgement, not completion** — Flutterwave refunds are
 *    asynchronous ("usually 3-15 working days"), so a `success` response
 *    here only means the refund was *accepted*, not that money has moved.
 * 3. **Local pending record** — {@see OrderTransactionService::createRefund()}
 *    creates (or, on a retry, re-finds) an `order_transaction_capture` and
 *    an `order_transaction_capture_refund` entity, both in their initial
 *    (pending) state.
 * 4. **Deterministic UUIDs** — both entity ids are derived from Flutterwave's
 *    own identifiers via `Uuid::fromStringToHex()` rather than generated
 *    randomly: the capture id from the Flutterwave transaction id (so every
 *    refund against the same transaction resolves to the same capture,
 *    closing a race where two concurrent requests could otherwise create two
 *    captures), and the refund id from the Flutterwave refund id (so a
 *    retried request is an idempotent no-op instead of a duplicate entity).
 *    See {@see OrderTransactionService::createRefund()} for the full
 *    rationale.
 * 5. **Pending state handling** — the refund stays pending in Shopware until
 *    Flutterwave confirms the outcome — unless the refund response already
 *    reports it `completed`, in which case it is completed on the spot via
 *    {@see RefundProcessor}. The admin transaction detail reads the
 *    local capture/refund records straight from the Shopware repositories to
 *    show that progress, so this controller only needs to *create* the refund,
 *    not serve it back for display.
 * 6. **Webhook correlation and completion** — the Flutterwave refund id is
 *    stored on the local refund's `externalReference`. When Flutterwave's
 *    `refund.completed` webhook arrives,
 *    `Webhook\Subscriber\RefundCompletedSubscriber` finds the matching
 *    pending refund by that value and transitions it to completed or failed,
 *    never trusting the webhook payload beyond the id used to find the
 *    record (see that subscriber's docblock for why).
 * 7. **Error handling and recovery** — once Flutterwave has accepted the
 *    refund, money is already moving; a failure creating the local record
 *    (step 3) is logged loudly but still reports success to the admin,
 *    because failing the HTTP request at that point would tell the merchant
 *    the refund did not happen when it did. The record can be reconciled
 *    later from Flutterwave's own refund history, which this controller
 *    already treats as authoritative. Failures *before* the Flutterwave call
 *    (eligibility, amount validation, the refund-list lookup used to compute
 *    the balance) are all safe to reject outright, since no money has moved.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class RefundController extends AbstractController
{
    public function __construct(
        private readonly FlutterwaveClient $flutterwave,
        private readonly OrderTransactionService $orderTransactionService,
        private readonly RefundEligibilityResolver $eligibilityResolver,
        private readonly FlutterwaveRefundLedgerInterface $refundLedger,
        private readonly RefundAmountCalculator $amountCalculator,
        private readonly RefundProcessor $refundProcessor,
        private readonly ConfigurableLogger $logger
    ) {
    }

    /**
     * Initiates a refund (full or partial) for an order transaction.
     *
     * See the class docblock for the full refund workflow this triggers.
     * Every failure returns an HTTP 400 with a merchant-facing `error`
     * message; nothing is thrown past this method.
     */
    #[Route(
        path: '/api/_action/flutterwave/refund',
        name: 'api.action.flutterwave.refund',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            '_acl' => ['flutterwave.refund'],
        ],
        methods: [Request::METHOD_POST]
    )]
    public function refund(Request $request, Context $context): JsonResponse
    {
        $orderTransactionId = $request->request->get('orderTransactionId');

        if (!is_string($orderTransactionId) || $orderTransactionId === '') {
            return $this->error('Order transaction id is required');
        }

        try {
            $transaction = $this->orderTransactionService->getForRefund($orderTransactionId, $context);
        } catch (\InvalidArgumentException) {
            return $this->error('Refundable transaction not found');
        }

        $salesChannelId = $transaction->getOrder()?->getSalesChannelId();

        try {
            $refundContext = $this->eligibilityResolver->resolve($transaction, $salesChannelId);
        } catch (RefundValidationException $exception) {
            return $this->error($exception->getMessage());
        }

        $amount = $request->request->get('amount');

        if ($amount !== null && (!is_numeric($amount) || (float)$amount <= 0.0)) {
            return $this->error('Refund amount must be a positive number');
        }

        try {
            $alreadyRefundedMinor = $this->refundLedger->alreadyRefundedMinor(
                $refundContext->flutterwaveTransactionId,
                $refundContext->currencyIso,
                $salesChannelId
            );
        } catch (\Throwable $exception) {
            $this->logger->error('[Flutterwave] Could not load existing refunds before refunding.', [
                ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId,
                'orderTransactionId' => $orderTransactionId,
                'exception' => $exception,
            ]);

            return $this->error('Unable to verify the refundable balance. Please try again.');
        }

        try {
            $refundAmountMajor = $this->amountCalculator->calculate(
                $refundContext,
                $alreadyRefundedMinor,
                $amount !== null ? (float)$amount : null
            );
        } catch (RefundValidationException $exception) {
            return $this->error($exception->getMessage());
        }

        $comments = $request->request->get('comments');
        $comments = is_string($comments) && trim($comments) !== '' ? trim($comments) : null;

        $this->logger->info('[Flutterwave] Refund requested.', [
            ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId,
            'orderTransactionId' => $orderTransactionId,
            'flutterwaveTransactionId' => $refundContext->flutterwaveTransactionId,
            'amount' => $refundAmountMajor,
            'currency' => $refundContext->currencyIso,
        ]);

        try {
            $response = $this->flutterwave->transactions()->refund(
                $refundContext->flutterwaveTransactionId,
                $refundAmountMajor,
                $comments,
                $salesChannelId
            );
        } catch (\Throwable $exception) {
            $this->logger->error('[Flutterwave] Refund request failed.', [
                ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId,
                'orderTransactionId' => $orderTransactionId,
                'flutterwaveTransactionId' => $refundContext->flutterwaveTransactionId,
                'exception' => $exception,
            ]);

            return $this->error($exception->getMessage());
        }

        if (($response['status'] ?? null) !== 'success') {
            $message = is_string($response['message'] ?? null) ? $response['message'] : 'Refund failed';

            $this->logger->error('[Flutterwave] Refund rejected by Flutterwave.', [
                ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId,
                'orderTransactionId' => $orderTransactionId,
                'flutterwaveTransactionId' => $refundContext->flutterwaveTransactionId,
                'response' => $response,
            ]);

            return $this->error($message);
        }

        // Flutterwave refunds are asynchronous ("usually 3-15 working days"), so
        // the API only ACKNOWLEDGES the request here. Record the refund locally in
        // its pending state and stamp it with Flutterwave's refund id: the
        // `refund.completed` webhook later finds it by that id and moves it to its
        // final state. Without the id there would be nothing to correlate against.
        $flutterwaveRefundId = $this->refundIdFromResponse($response);

        try {
            $refundId = $this->orderTransactionService->createRefund(
                $orderTransactionId,
                $refundContext->flutterwaveTransactionId,
                $refundAmountMajor,
                $context,
                $flutterwaveRefundId
            );
        } catch (\Throwable $exception) { // @codeCoverageIgnoreStart
            // The money is already moving at Flutterwave; failing the request now
            // would tell the merchant the refund did not happen. Report success
            // and log loudly — the record can be reconciled from the refund
            // history, which reads from Flutterwave rather than local state.
            $this->logger->error('[Flutterwave] Refund succeeded at Flutterwave but the local record could not be created.', [
                ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId,
                'orderTransactionId' => $orderTransactionId,
                'flutterwaveTransactionId' => $refundContext->flutterwaveTransactionId,
                'flutterwaveRefundId' => $flutterwaveRefundId,
                'exception' => $exception,
            ]);

            return new JsonResponse($response);
        } // @codeCoverageIgnoreEnd

        // Flutterwave sometimes settles a refund synchronously (the sandbox
        // always does). Complete it now rather than leaving it pending on a
        // webhook that may never come; a later webhook finds it final and stops.
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $status = is_string($data['status'] ?? null) ? strtolower($data['status']) : null;

        if (in_array($status, FlutterwaveConstants::REFUND_SUCCESS_STATUSES, true)) {
            try {
                $this->refundProcessor->process(new RefundPaymentTransactionStruct($refundId, $orderTransactionId), $context);

                return new JsonResponse($response);
            } catch (\Throwable $exception) {
                $this->logger->error('[Flutterwave] Refund completed at Flutterwave but could not be completed locally.', [
                    ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId,
                    'orderTransactionId' => $orderTransactionId,
                    'refundId' => $refundId,
                    'exception' => $exception,
                ]);
            }
        }

        $this->logger->info('[Flutterwave] Refund requested; awaiting refund.completed webhook.', [
            ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId,
            'orderTransactionId' => $orderTransactionId,
            'flutterwaveTransactionId' => $refundContext->flutterwaveTransactionId,
            'flutterwaveRefundId' => $flutterwaveRefundId,
            'refundId' => $refundId,
        ]);

        return new JsonResponse($response);
    }

    /**
     * The Flutterwave refund id from a refund response (`data.id`), used as the
     * correlation key for the webhook. Null when Flutterwave did not return one,
     * in which case the local refund still records the attempt but the webhook
     * cannot match it automatically.
     *
     * @param array<string, mixed> $response
     */
    private function refundIdFromResponse(array $response): ?string
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $id = $data['id'] ?? null;

        return is_numeric($id) ? (string)$id : null;
    }

    private function error(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_BAD_REQUEST);
    }
}
