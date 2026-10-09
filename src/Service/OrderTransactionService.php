<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;

/**
 * OrderTransactionService handles common operations for retrieving and updating order transactions.
 * It ensures the required associations are loaded for payment processing.
 */
class OrderTransactionService
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        private readonly EntityRepository $orderTransactionCaptureRepository,
        private readonly EntityRepository $orderTransactionCaptureRefundRepository,
        private readonly InitialStateIdLoader $initialStateIdLoader,
    ) {
    }

    /**
     * Updates an order transaction in the database.
     *
     * @param array $payload The update payload.
     * @param Context $context The Shopware context.
     */
    public function update(array $payload, Context $context): void
    {
        $this->orderTransactionRepository->update($payload, $context);
    }

    /**
     * Retrieves an order transaction with necessary associations for Flutterwave.
     *
     * @param string $transactionId The transaction ID.
     * @param Context $context The Shopware context.
     *
     * @return OrderTransactionEntity The order transaction.
     *
     * @throws \InvalidArgumentException If the transaction is not found.
     */
    public function getOrderTransaction(string $transactionId, Context $context): OrderTransactionEntity
    {
        $criteria = $this->getCriteria([$transactionId]);
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$orderTransaction instanceof OrderTransactionEntity) {
            throw new \InvalidArgumentException(sprintf(
                'Order transaction with id %s not found',
                $transactionId
            ));
        }

        return $orderTransaction;
    }

    /**
     * Retrieves an order transaction with the associations the admin refund flow
     * needs: the order currency (to convert amounts) and the transaction state
     * (to check it is refundable).
     *
     * @throws \InvalidArgumentException If the transaction is not found.
     */
    public function getForRefund(string $transactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('stateMachineState');

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$orderTransaction instanceof OrderTransactionEntity) {
            throw new \InvalidArgumentException(sprintf('Order transaction with id %s not found', $transactionId));
        }

        return $orderTransaction;
    }

    /**
     * Creates a refund for the given order transaction.
     * It ensures a capture exists and creates a refund entity.
     *
     * Both entity IDs are deterministic, derived from Flutterwave's own
     * identifiers via {@see Uuid::fromStringToHex()} rather than randomly
     * generated:
     *
     *  - The capture ID is derived from the Flutterwave transaction id
     *    (`tx_id`). A capture represents "this Flutterwave transaction's
     *    refundable balance", so every refund against the same transaction
     *    must resolve to the same capture — a random ID checked only against
     *    the transaction's already-loaded captures collection could create a
     *    second capture under a concurrent request.
     *  - The refund ID is derived from the Flutterwave refund id
     *    (`data.id`), which is unique per refund attempt. This makes a
     *    retried admin request (e.g. after a timeout on the first attempt's
     *    local write) an idempotent no-op instead of a duplicate refund
     *    entity, and gives the `refund.completed` webhook a stable id to
     *    correlate against in addition to the `externalReference` field.
     *
     * @param string $orderTransactionId The transaction ID.
     * @param string $flutterwaveTransactionId The Flutterwave transaction id (`tx_id`).
     * @param float $amount The refund amount in major units.
     * @param Context $context The Shopware context.
     * @param string|null $flutterwaveRefundId The Flutterwave refund id (`data.id`).
     *                                         This is also the correlation key: the
     *                                         refund is created in its initial
     *                                         (pending) state and only completed
     *                                         once the matching `refund.completed`
     *                                         webhook arrives, which finds it again
     *                                         by this value in `externalReference`.
     *
     * @return string The ID of the created (or already-existing) refund entity.
     */
    public function createRefund(
        string $orderTransactionId,
        string $flutterwaveTransactionId,
        float $amount,
        Context $context,
        ?string $flutterwaveRefundId = null
    ): string {
        $orderTransaction = $this->getOrderTransaction($orderTransactionId, $context);

        $captureId = $this->getOrCreateCapture($orderTransaction, $flutterwaveTransactionId, $context);

        // Flutterwave does not always return a refund id (e.g. an unexpected
        // response shape); without one there is nothing to derive a stable id
        // from, so fall back to a random one. The webhook cannot correlate
        // against this refund automatically in that case, which is a
        // pre-existing limitation of a missing refund id, not introduced here.
        $refundId = $flutterwaveRefundId !== null
            ? Uuid::fromStringToHex('flutterwave-refund-' . $flutterwaveRefundId)
            : Uuid::randomHex(); // @codeCoverageIgnore

        if ($this->entityExists($this->orderTransactionCaptureRefundRepository, $refundId, $context)) {
            return $refundId;
        }

        $this->orderTransactionCaptureRefundRepository->create([
            [
                'id' => $refundId,
                'captureId' => $captureId,
                'stateId' => $this->initialStateIdLoader->get(OrderTransactionCaptureRefundStates::STATE_MACHINE),
                'externalReference' => $flutterwaveRefundId,
                'amount' => [
                    'unitPrice' => $amount,
                    'totalPrice' => $amount,
                    'quantity' => 1,
                    'calculatedTaxes' => [],
                    'taxRules' => [],
                ],
            ],
        ], $context);

        return $refundId;
    }

    /**
     * Returns the capture for this Flutterwave transaction, creating it if it
     * does not exist yet. Keyed deterministically by the Flutterwave
     * transaction id so concurrent or retried refund requests against the
     * same transaction resolve to one capture instead of racing to create two.
     */
    private function getOrCreateCapture(
        OrderTransactionEntity $orderTransaction,
        string $flutterwaveTransactionId,
        Context $context
    ): string {
        $captureId = Uuid::fromStringToHex('flutterwave-capture-' . $flutterwaveTransactionId);

        if ($this->entityExists($this->orderTransactionCaptureRepository, $captureId, $context)) {
            return $captureId;
        }

        $this->orderTransactionCaptureRepository->create([
            [
                'id' => $captureId,
                'orderTransactionId' => $orderTransaction->getId(),
                'stateId' => $this->initialStateIdLoader->get(OrderTransactionCaptureStates::STATE_MACHINE),
                'amount' => [
                    'unitPrice' => $orderTransaction->getAmount()->getTotalPrice(),
                    'totalPrice' => $orderTransaction->getAmount()->getTotalPrice(),
                    'quantity' => 1,
                    'calculatedTaxes' => $orderTransaction->getAmount()->getCalculatedTaxes(),
                    'taxRules' => $orderTransaction->getAmount()->getTaxRules(),
                ],
            ],
        ], $context);

        return $captureId;
    }

    private function entityExists(EntityRepository $repository, string $id, Context $context): bool
    {
        return $repository->searchIds(new Criteria([$id]), $context)->getTotal() > 0;
    }

    /**
     * Finds a pending refund by the Flutterwave refund id stored on it.
     *
     * This is the webhook correlation lookup: `refund.completed` carries the
     * Flutterwave refund id, which was written to `externalReference` when the
     * refund was created, so the right pending record can be found again without
     * trusting anything else in the payload.
     */
    public function findRefundByExternalReference(string $externalReference, Context $context): ?OrderTransactionCaptureRefundEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('externalReference', $externalReference));
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('transactionCapture.transaction.order');
        $criteria->setLimit(1);

        $refund = $this->orderTransactionCaptureRefundRepository->search($criteria, $context)->first();

        return $refund instanceof OrderTransactionCaptureRefundEntity ? $refund : null;
    }

    /**
     * Builds the criteria with necessary associations.
     *
     * @param array $ids Optional transaction IDs.
     *
     * @return Criteria The search criteria.
     */
    private function getCriteria(array $ids = []): Criteria
    {
        $criteria = empty($ids) ? new Criteria() : new Criteria($ids);
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.orderCustomer.salutation');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('captures.refunds.stateMachineState');

        return $criteria;
    }
}
