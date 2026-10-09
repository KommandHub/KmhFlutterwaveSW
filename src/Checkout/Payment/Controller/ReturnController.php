<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Checkout\Payment\Controller;

use Kommandhub\FlutterwaveSW\Service\OrderTransactionService;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveConstants;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where Flutterwave sends the customer back to after checkout.
 *
 * Flutterwave is never given Shopware's finalize URL directly: its 3DS flow
 * replaces the query string with `?response={json}`, dropping the
 * `_sw_payment_token` Shopware needs. This route looks the real finalize URL up
 * (stored by PaymentProcessor), translates whichever callback shape arrived into
 * the `status` / `transaction_id` parameters FinalizeProcessor reads, and
 * forwards the customer there.
 *
 * Nothing forwarded is trusted: FinalizeProcessor re-verifies the payment with
 * the Flutterwave API and binds it to this transaction's `tx_ref`.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
class ReturnController extends AbstractController
{
    public function __construct(private readonly OrderTransactionService $orderTransactionService)
    {
    }

    #[Route(
        path: '/flutterwave/return/{orderTransactionId}/{nonce}',
        name: 'frontend.flutterwave.return',
        methods: [Request::METHOD_GET]
    )]
    public function return(string $orderTransactionId, string $nonce, Request $request, Context $context): RedirectResponse
    {
        if (!Uuid::isValid($orderTransactionId)) {
            throw new NotFoundHttpException();
        }

        try {
            $transaction = $this->orderTransactionService->getOrderTransaction($orderTransactionId, $context);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }

        $stored = $transaction->getCustomFields()[FlutterwaveConstants::FIELD_RETURN] ?? null;
        $storedNonce = is_array($stored) ? ($stored['nonce'] ?? null) : null;
        $finalizeUrl = is_array($stored) ? ($stored['url'] ?? null) : null;

        if (!is_string($storedNonce) || !is_string($finalizeUrl) || !hash_equals($storedNonce, $nonce)) {
            throw new NotFoundHttpException();
        }

        $params = $this->callbackParams($request);

        if ($params === []) {
            return new RedirectResponse($finalizeUrl);
        }

        $separator = str_contains($finalizeUrl, '?') ? '&' : '?';

        return new RedirectResponse($finalizeUrl . $separator . http_build_query($params));
    }

    /**
     * Normalises both callback shapes:
     *  - hosted checkout: `?status=successful&tx_ref=…&transaction_id=…`
     *  - card 3DS:        `?response={"id":…,"status":"successful",…}`
     *
     * @return array<string, string>
     */
    private function callbackParams(Request $request): array
    {
        $status = $request->query->get('status');
        $transactionId = $request->query->get('transaction_id');

        $response = $request->query->get('response');

        if (is_string($response)) {
            $data = json_decode($response, true);

            if (is_array($data)) {
                $status = $data['status'] ?? $status;
                $transactionId = $data['id'] ?? $transactionId;
            }
        }

        return array_filter([
            'status' => is_scalar($status) ? (string)$status : '',
            'transaction_id' => is_scalar($transactionId) ? (string)$transactionId : '',
        ], static fn (string $value): bool => $value !== '');
    }
}
