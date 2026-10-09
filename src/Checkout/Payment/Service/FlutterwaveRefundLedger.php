<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Checkout\Payment\Service;

use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Exception\FlutterwaveException;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveCurrencyHelper;

/**
 * Flutterwave-API-backed implementation of {@see FlutterwaveRefundLedgerInterface}.
 *
 * @final
 */
final readonly class FlutterwaveRefundLedger implements FlutterwaveRefundLedgerInterface
{
    /**
     * The only Flutterwave refund status that frees a refund's amount back
     * into the refundable balance. Every other status — completed,
     * successful, pending, or one Flutterwave adds later — is treated as
     * still consuming balance.
     *
     * This is a deliberate denylist rather than an allowlist of "active"
     * statuses: an allowlist that omitted a real status (e.g. "successful",
     * which Flutterwave uses alongside "completed") would under-count what
     * has already been refunded and let the guard authorise an over-refund.
     * Failing safe on money means counting anything not explicitly failed.
     */
    private const REFUND_FREED_STATUS = 'failed';

    public function __construct(private FlutterwaveClient $flutterwave)
    {
    }

    /**
     * Upper bound on refund-list pages walked per lookup.
     */
    private const MAX_PAGES = 50;

    /**
     * {@inheritDoc}
     *
     * Flutterwave has no endpoint that returns only a single transaction's
     * refunds: `GET /refunds?id=` is documented to filter by transaction id,
     * but in practice every filter is ignored and the account-wide list comes
     * back, paginated.
     *
     * Refund objects do not carry the public transaction id either: their
     * `transaction_id` is an internal Flutterwave id. What they do carry is the
     * parent charge's `flw_ref`, so the transaction is verified once to learn
     * its `flw_ref` and every page of the list is filtered on that.
     *
     * @throws FlutterwaveException When the transaction or a refund page cannot be loaded.
     */
    public function refundsForTransaction(string $flutterwaveTransactionId, ?string $salesChannelId): array
    {
        $flwRef = $this->flwRefFor($flutterwaveTransactionId, $salesChannelId);

        $matching = [];

        // ponytail: walks the whole account-wide list, capped at MAX_PAGES; if
        // accounts outgrow that, persist the refunded total locally instead.
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $response = $this->flutterwave->transactions()->refunds($flutterwaveTransactionId, $salesChannelId, $page);
            $refunds = is_array($response['data'] ?? null) ? $response['data'] : [];

            foreach ($refunds as $refund) {
                if (is_array($refund) && ($refund['flw_ref'] ?? null) === $flwRef) {
                    $matching[] = $refund;
                }
            }

            $meta = is_array($response['meta'] ?? null) ? $response['meta'] : [];
            $pageInfo = is_array($meta['page_info'] ?? null) ? $meta['page_info'] : [];
            $totalPages = $pageInfo['total_pages'] ?? null;

            if ($refunds === [] || !is_numeric($totalPages) || $page >= (int)$totalPages) {
                break;
            }
        }

        return $matching;
    }

    /**
     * @throws FlutterwaveException When the transaction cannot be verified or has no flw_ref.
     */
    private function flwRefFor(string $flutterwaveTransactionId, ?string $salesChannelId): string
    {
        $response = $this->flutterwave->transactions()->verify($flutterwaveTransactionId, $salesChannelId);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $flwRef = $data['flw_ref'] ?? null;

        if (!is_string($flwRef) || $flwRef === '') {
            throw new FlutterwaveException(sprintf('Flutterwave transaction %s has no flw_ref.', $flutterwaveTransactionId));
        }

        return $flwRef;
    }

    public function alreadyRefundedMinor(
        string $flutterwaveTransactionId,
        string $currencyIso,
        ?string $salesChannelId
    ): int {
        $refunds = $this->refundsForTransaction($flutterwaveTransactionId, $salesChannelId);

        $total = 0;

        foreach ($refunds as $refund) {
            $status = is_string($refund['status'] ?? null) ? strtolower($refund['status']) : '';

            // Count every refund that has not explicitly failed (see the
            // REFUND_FREED_STATUS note), so an unrecognised status cannot
            // open an over-refund.
            if ($status === self::REFUND_FREED_STATUS) {
                continue;
            }

            // Flutterwave reports the refunded value as `amount_refunded`.
            $amount = $refund['amount_refunded'] ?? $refund['amount'] ?? 0;

            if (is_numeric($amount)) {
                $total += FlutterwaveCurrencyHelper::toMinorUnit((float)$amount, $currencyIso);
            }
        }

        return $total;
    }
}
