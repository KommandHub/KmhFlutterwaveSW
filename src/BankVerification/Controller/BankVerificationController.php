<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\BankVerification\Controller;

use Kommandhub\FlutterwaveSW\BankVerification\Service\BankValidationFactory;
use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Logging\ConfigurableLogger;
use Kommandhub\FlutterwaveSW\Setting\Service\Config;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveConstants;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\RateLimiter\Exception\RateLimitExceededException;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Storefront endpoints backing the customer bank-profile form.
 *
 * Improvements over the Paystack equivalent:
 * - Talks to Flutterwave through the typed {@see FlutterwaveClient} rather than
 *   issuing raw HTTP from the controller, so the endpoint, auth and error-body
 *   handling live in one tested place.
 * - Emits structured, sales-channel-scoped logs (the Paystack controller logged
 *   nothing), while never logging the account number or BVN.
 * - Normalises Flutterwave's response shape to a stable `{status: bool, data}`
 *   contract, so the storefront JS is decoupled from provider specifics.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class BankVerificationController extends StorefrontController
{
    public const RATE_LIMIT_VERIFY = 'kmh_flutterwave_bank_verify';

    /**
     * The last account Flutterwave resolved for this session. Save only accepts
     * this account, with this name — otherwise a customer could skip
     * verification and store any name against any account number.
     */
    public const SESSION_VERIFIED_ACCOUNT = 'kmh_flutterwave_verified_account';

    public function __construct(
        private readonly FlutterwaveClient $flutterwave,
        private readonly Config $config,
        private readonly EntityRepository $customerRepository,
        private readonly BankValidationFactory $bankValidationFactory,
        private readonly DataValidator $validator,
        private readonly RateLimiter $rateLimiter,
        private readonly ConfigurableLogger $logger
    ) {
    }

    /**
     * Lists the banks Flutterwave supports for the configured country.
     */
    #[Route(
        path: '/flutterwave/bank/list',
        name: 'frontend.flutterwave.bank.list',
        defaults: ['XmlHttpRequest' => true, PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true],
        methods: ['GET']
    )]
    public function getBanks(SalesChannelContext $context): JsonResponse
    {
        $salesChannelId = $context->getSalesChannelId();

        if (!$this->isBankDataCollectionEnabled($salesChannelId)) {
            return new JsonResponse(['status' => false, 'message' => 'Feature disabled'], Response::HTTP_NOT_FOUND);
        }

        try {
            $response = $this->flutterwave->banks()->list($this->bankCountry($salesChannelId), $salesChannelId);
            $data = is_array($response['data'] ?? null) ? $response['data'] : [];

            return new JsonResponse(['status' => true, 'data' => array_values($data)]);
        } catch (\Throwable $e) {
            $this->logger->error('[Flutterwave] Failed to load banks.', [
                ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId,
                'exception' => $e,
            ]);

            return new JsonResponse(['status' => false, 'message' => 'Unable to load banks.'], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Resolves an account number to its holder name via Flutterwave.
     */
    #[Route(
        path: '/flutterwave/bank/verify',
        name: 'frontend.flutterwave.bank.verify',
        defaults: ['XmlHttpRequest' => true, PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true],
        methods: ['POST']
    )]
    public function verifyAccount(Request $request, SalesChannelContext $context): JsonResponse
    {
        $salesChannelId = $context->getSalesChannelId();

        if (!$this->isBankDataCollectionEnabled($salesChannelId)) {
            return new JsonResponse(['status' => false, 'message' => 'Feature disabled'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->rateLimiter->ensureAccepted(self::RATE_LIMIT_VERIFY, (string)$context->getCustomerId());
        } catch (RateLimitExceededException) {
            return new JsonResponse(['status' => false, 'message' => 'Too many verification attempts. Please try again later.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $accountNumber = trim((string)$request->request->get('account_number'));
        $bankCode = trim((string)$request->request->get('bank_code'));

        if ($accountNumber === '' || $bankCode === '') {
            return new JsonResponse(['status' => false, 'message' => 'Missing parameters'], Response::HTTP_BAD_REQUEST);
        }

        // Flutterwave's sandbox resolves only against bank code 044; any other is
        // rejected outright. Send the sandbox bank so verification works in test
        // mode — the customer's real selection is persisted separately on save.
        $resolveBankCode = $this->config->isSandbox($salesChannelId)
            ? FlutterwaveConstants::SANDBOX_ACCOUNT_BANK
            : $bankCode;

        try {
            $response = $this->flutterwave->banks()->resolveAccount($accountNumber, $resolveBankCode, $salesChannelId);
            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $accountName = is_string($data['account_name'] ?? null) ? $data['account_name'] : null;

            if (($response['status'] ?? null) !== 'success' || $accountName === null || $accountName === '') {
                return new JsonResponse([
                    'status' => false,
                    'message' => is_string($response['message'] ?? null) ? $response['message'] : 'Account verification failed.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $request->getSession()->set(self::SESSION_VERIFIED_ACCOUNT, [
                'accountNumber' => $accountNumber,
                'bankCode' => $bankCode,
                'accountName' => $accountName,
            ]);

            return new JsonResponse(['status' => true, 'data' => ['account_name' => $accountName]]);
        } catch (\Throwable $e) {
            // Never log the account number — it is customer financial data.
            $this->logger->error('[Flutterwave] Account resolution failed.', [
                ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId,
                'bankCode' => $bankCode,
                'exception' => $e,
            ]);

            return new JsonResponse(['status' => false, 'message' => 'Verification service unavailable.'], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Persists the verified bank profile onto the customer record.
     */
    #[Route(
        path: '/flutterwave/bank/save',
        name: 'frontend.flutterwave.bank.save',
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true],
        methods: ['POST']
    )]
    public function saveBank(Request $request, RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): Response
    {
        if (!$this->isBankDataCollectionEnabled($context->getSalesChannelId())) {
            throw $this->createNotFoundException();
        }

        $validation = $this->bankValidationFactory->create($context);
        $violations = $this->validator->getViolations($data->all(), $validation);

        if ($violations->count() > 0) {
            $this->addFlash(StorefrontController::DANGER, $this->trans('kmh-flutterwave.bank.saveError'));

            foreach ($violations as $violation) {
                $this->addFlash(StorefrontController::DANGER, $violation->getMessage());
            }

            return $this->redirectToRoute('frontend.account.profile.page');
        }

        $verified = $request->getSession()->get(self::SESSION_VERIFIED_ACCOUNT);

        if (!is_array($verified)
            || ($verified['accountNumber'] ?? null) !== trim($data->getString('accountNumber'))
            || ($verified['bankCode'] ?? null) !== trim($data->getString('bankCode'))
        ) {
            $this->addFlash(StorefrontController::DANGER, $this->trans('kmh-flutterwave.bank.notVerified'));

            return $this->redirectToRoute('frontend.account.profile.page');
        }

        // The name comes from Flutterwave's resolution, not from the form.
        $customFields = [
            FlutterwaveConstants::CUSTOMER_FIELD_BANK_NAME => $data->get('bankName'),
            FlutterwaveConstants::CUSTOMER_FIELD_BANK_CODE => $verified['bankCode'],
            FlutterwaveConstants::CUSTOMER_FIELD_ACCOUNT_NUMBER => $verified['accountNumber'],
            FlutterwaveConstants::CUSTOMER_FIELD_ACCOUNT_NAME => $verified['accountName'],
        ];

        $bvn = $data->get('bvn');

        if (is_string($bvn) && $bvn !== '') {
            $customFields[FlutterwaveConstants::CUSTOMER_FIELD_BVN] = $bvn;
        }

        $this->customerRepository->update([
            [
                'id' => $customer->getId(),
                'customFields' => $customFields,
            ],
        ], $context->getContext());

        $request->getSession()->remove(self::SESSION_VERIFIED_ACCOUNT);

        $this->addFlash(StorefrontController::SUCCESS, $this->trans('kmh-flutterwave.bank.saveSuccess'));

        return $this->redirectToRoute('frontend.account.profile.page');
    }

    /**
     * ISO 3166-1 alpha-2 country whose bank list is shown. Defaults to Nigeria,
     * the market where 10-digit accounts and BVN apply; overridable per channel.
     */
    private function bankCountry(?string $salesChannelId): string
    {
        $country = strtoupper($this->config->getString('bankCountry', $salesChannelId));

        return $country !== '' ? $country : 'NG';
    }

    private function isBankDataCollectionEnabled(?string $salesChannelId): bool
    {
        return $this->config->getBool('collectBankData', $salesChannelId);
    }
}
