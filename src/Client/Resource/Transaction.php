<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Client\Resource;

use Kommandhub\FlutterwaveSW\Exception\FlutterwaveException;

/**
 * Class Transaction.
 */
class Transaction extends ApiResource
{
    /**
     * Initialize a Standard checkout and get the hosted payment link.
     *
     * Amounts in $payload are MAJOR units (100 == NGN 100.00).
     *
     * @see https://developer.flutterwave.com/v3.0/docs/flutterwave-standard-1
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws FlutterwaveException
     */
    public function initialize(array $payload, ?string $salesChannelId = null): array
    {
        return $this->response($this->httpClient->post('/payments', $payload, $salesChannelId));
    }

    /**
     * Verify a transaction by Flutterwave's own numeric transaction id.
     *
     * @see https://developer.flutterwave.com/v3.0/docs/transaction-verification
     *
     * @return array<string, mixed>
     *
     * @throws FlutterwaveException
     */
    public function verify(string $transactionId, ?string $salesChannelId = null): array
    {
        return $this->response($this->httpClient->get("/transactions/{$transactionId}/verify", [], $salesChannelId));
    }

    /**
     * Verify a transaction by the merchant-supplied `tx_ref`.
     *
     * Needed on the webhook path: `charge.completed` is keyed by `tx_ref`, and
     * the payload's own `id` must not be trusted to address the verify call —
     * that would let a replayed body point verification at a different
     * transaction.
     *
     * @return array<string, mixed>
     *
     * @throws FlutterwaveException
     */
    public function verifyByReference(string $txRef, ?string $salesChannelId = null): array
    {
        return $this->response($this->httpClient->get('/transactions/verify_by_reference', [
            'tx_ref' => $txRef,
        ], $salesChannelId));
    }

    /**
     * Refund a transaction, in whole or in part.
     *
     * `amount` is MAJOR units and is omitted for a full refund.
     *
     * @see https://developer.flutterwave.com/v3.0/docs/refunds
     *
     * @return array<string, mixed>
     *
     * @throws FlutterwaveException
     */
    public function refund(string $transactionId, ?float $amount = null, ?string $comments = null, ?string $salesChannelId = null): array
    {
        $payload = [];

        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        if ($comments !== null) {
            $payload['comments'] = $comments;
        }

        return $this->response($this->httpClient->post("/transactions/{$transactionId}/refund", $payload, $salesChannelId));
    }

    /**
     * List the refunds already raised against a transaction.
     *
     * Flutterwave V3 exposes this through the refunds collection filtered by the
     * transaction id — `GET /refunds?id={id}`, where `id` is the numeric
     * transaction id returned as `data.id` by verify. There is no
     * `/transactions/{id}/refunds` GET route (only the POST refund action lives
     * under /transactions); requesting it returns a 404 HTML page, which then
     * fails JSON decoding with "Syntax error for URL …".
     *
     * @see https://developer.flutterwave.com/v3.0.0/reference/get-all-refunds
     *
     * @return array<string, mixed>
     *
     * @throws FlutterwaveException
     */
    public function refunds(string $transactionId, ?string $salesChannelId = null, int $page = 1): array
    {
        return $this->response($this->httpClient->get('/refunds', ['id' => $transactionId, 'page' => $page], $salesChannelId));
    }

    /**
     * List transactions.
     *
     * @param array<string, mixed> $queryParams
     *
     * @return array<string, mixed>
     *
     * @throws FlutterwaveException
     */
    public function list(array $queryParams = [], ?string $salesChannelId = null): array
    {
        return $this->response($this->httpClient->get('/transactions', $queryParams, $salesChannelId));
    }
}
