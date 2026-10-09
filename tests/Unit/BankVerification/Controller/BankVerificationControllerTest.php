<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit\BankVerification\Controller;

use Kommandhub\FlutterwaveSW\BankVerification\Controller\BankVerificationController;
use Kommandhub\FlutterwaveSW\BankVerification\Service\BankValidationFactory;
use Kommandhub\FlutterwaveSW\Client\FlutterwaveClient;
use Kommandhub\FlutterwaveSW\Client\Resource\Bank;
use Kommandhub\FlutterwaveSW\Logging\ConfigurableLogger;
use Kommandhub\FlutterwaveSW\Setting\Service\Config;
use Kommandhub\FlutterwaveSW\Util\FlutterwaveConstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\RateLimiter\Exception\RateLimitExceededException;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationList;

#[CoversClass(BankVerificationController::class)]
#[UsesClass(BankValidationFactory::class)]
class BankVerificationControllerTest extends TestCase
{
    private FlutterwaveClient&MockObject $flutterwave;
    private Bank&MockObject $bank;
    private Config&MockObject $config;
    private EntityRepository&MockObject $customerRepository;
    private BankValidationFactory&MockObject $bankValidationFactory;
    private DataValidator&MockObject $validator;
    private ConfigurableLogger&MockObject $logger;
    private RateLimiter&MockObject $rateLimiter;

    protected function setUp(): void
    {
        $this->flutterwave = $this->createMock(FlutterwaveClient::class);
        $this->bank = $this->createMock(Bank::class);
        $this->flutterwave->method('banks')->willReturn($this->bank);
        $this->config = $this->createMock(Config::class);
        $this->customerRepository = $this->createMock(EntityRepository::class);
        $this->bankValidationFactory = $this->createMock(BankValidationFactory::class);
        $this->validator = $this->createMock(DataValidator::class);
        $this->logger = $this->createMock(ConfigurableLogger::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
    }

    /**
     * @param array<string, string>|null $verified what an earlier verify call left in the session
     */
    private function sessionRequest(?array $verified = null): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        if ($verified !== null) {
            $request->getSession()->set(BankVerificationController::SESSION_VERIFIED_ACCOUNT, $verified);
        }

        return $request;
    }

    private const VERIFIED = ['accountNumber' => '0690000032', 'bankCode' => '044', 'accountName' => 'Ada Lovelace'];

    private function controller(): BankVerificationController
    {
        return new BankVerificationController(
            $this->flutterwave,
            $this->config,
            $this->customerRepository,
            $this->bankValidationFactory,
            $this->validator,
            $this->rateLimiter,
            $this->logger
        );
    }

    private function context(): SalesChannelContext&MockObject
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }

    private function enable(bool $enabled = true): void
    {
        $this->config->method('getBool')->willReturnCallback(
            static fn (string $key): bool => $key === 'collectBankData' ? $enabled : false
        );
    }

    // --- getBanks -----------------------------------------------------------

    public function testGetBanksReturnsNormalisedList(): void
    {
        $this->enable();
        $this->config->method('getString')->with('bankCountry', 'sales-channel-id')->willReturn('NG');

        $this->bank->expects(static::once())
            ->method('list')
            ->with('NG', 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => [['id' => 1, 'code' => '044', 'name' => 'Access Bank']]]);

        $result = $this->controller()->getBanks($this->context());

        static::assertInstanceOf(JsonResponse::class, $result);
        static::assertSame(200, $result->getStatusCode());
        static::assertSame(
            '{"status":true,"data":[{"id":1,"code":"044","name":"Access Bank"}]}',
            $result->getContent()
        );
    }

    public function testGetBanksDefaultsCountryToNigeria(): void
    {
        $this->enable();
        $this->config->method('getString')->willReturn('');

        $this->bank->expects(static::once())->method('list')->with('NG', 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => []]);

        $this->controller()->getBanks($this->context());
    }

    public function testGetBanksReturns404WhenDisabled(): void
    {
        $this->enable(false);

        static::assertSame(404, $this->controller()->getBanks($this->context())->getStatusCode());
    }

    public function testGetBanksReturns502AndLogsOnApiFailure(): void
    {
        $this->enable();
        $this->config->method('getString')->willReturn('NG');
        $this->bank->method('list')->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects(static::once())->method('error');

        static::assertSame(502, $this->controller()->getBanks($this->context())->getStatusCode());
    }

    // --- verifyAccount ------------------------------------------------------

    private function verifyRequest(): Request
    {
        $request = new Request([], ['account_number' => '0690000032', 'bank_code' => '044']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    public function testVerifyAccountReturnsResolvedName(): void
    {
        $this->enable();
        $this->bank->expects(static::once())
            ->method('resolveAccount')
            ->with('0690000032', '044', 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => ['account_name' => 'Ada Lovelace']]);

        $result = $this->controller()->verifyAccount($this->verifyRequest(), $this->context());

        static::assertSame(200, $result->getStatusCode());
        static::assertSame('{"status":true,"data":{"account_name":"Ada Lovelace"}}', $result->getContent());
    }

    public function testVerifyAccountForcesSandboxBankCodeInTestMode(): void
    {
        // Feature on AND sandbox on. Flutterwave's sandbox rejects every bank
        // code except 044, so a customer selecting any other code must still
        // resolve against 044 in test mode.
        $this->config->method('getBool')->willReturnCallback(
            static fn (string $key): bool => in_array($key, ['collectBankData', 'enableSandbox'], true)
        );
        $this->config->method('isSandbox')->with('sales-channel-id')->willReturn(true);

        $this->bank->expects(static::once())
            ->method('resolveAccount')
            ->with('0690000032', FlutterwaveConstants::SANDBOX_ACCOUNT_BANK, 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => ['account_name' => 'Ada Lovelace']]);

        $request = new Request([], ['account_number' => '0690000032', 'bank_code' => '058']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $result = $this->controller()->verifyAccount($request, $this->context());

        static::assertSame(200, $result->getStatusCode());
    }

    public function testVerifyAccountUsesSelectedBankCodeInLiveMode(): void
    {
        $this->enable();
        $this->config->method('isSandbox')->willReturn(false);

        $this->bank->expects(static::once())
            ->method('resolveAccount')
            ->with('0690000032', '058', 'sales-channel-id')
            ->willReturn(['status' => 'success', 'data' => ['account_name' => 'Ada Lovelace']]);

        $request = new Request([], ['account_number' => '0690000032', 'bank_code' => '058']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        static::assertSame(200, $this->controller()->verifyAccount($request, $this->context())->getStatusCode());
    }

    public function testVerifyAccountReturns404WhenDisabled(): void
    {
        $this->enable(false);

        static::assertSame(404, $this->controller()->verifyAccount($this->verifyRequest(), $this->context())->getStatusCode());
    }

    public function testVerifyAccountReturns400OnMissingParameters(): void
    {
        $this->enable();

        static::assertSame(400, $this->controller()->verifyAccount(new Request(), $this->context())->getStatusCode());
    }

    public function testVerifyAccountReturns400WhenFlutterwaveReportsFailure(): void
    {
        $this->enable();
        $this->bank->method('resolveAccount')->willReturn(['status' => 'error', 'message' => 'Could not resolve account']);

        $result = $this->controller()->verifyAccount($this->verifyRequest(), $this->context());

        static::assertSame(400, $result->getStatusCode());
        static::assertStringContainsString('Could not resolve account', (string)$result->getContent());
    }

    public function testVerifyAccountReturns400WhenAccountNameMissing(): void
    {
        $this->enable();
        $this->bank->method('resolveAccount')->willReturn(['status' => 'success', 'data' => []]);

        static::assertSame(400, $this->controller()->verifyAccount($this->verifyRequest(), $this->context())->getStatusCode());
    }

    public function testVerifyAccountReturns502AndLogsOnException(): void
    {
        $this->enable();
        $this->bank->method('resolveAccount')->willThrowException(new \RuntimeException('network'));

        $this->logger->expects(static::once())->method('error');

        static::assertSame(502, $this->controller()->verifyAccount($this->verifyRequest(), $this->context())->getStatusCode());
    }

    // --- saveBank -----------------------------------------------------------

    public function testSaveBankThrowsNotFoundWhenDisabled(): void
    {
        $this->enable(false);

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->saveBank($this->sessionRequest(), new RequestDataBag(), $this->context(), new CustomerEntity());
    }

    public function testSaveBankPersistsAndRedirectsOnValidData(): void
    {
        $this->enable();
        $this->bankValidationFactory->method('create')->willReturn(new DataValidationDefinition());
        $this->validator->method('getViolations')->willReturn(new ConstraintViolationList());

        $controller = $this->createPartialMock(BankVerificationController::class, ['addFlash', 'redirectToRoute', 'trans']);
        $controller->__construct(
            $this->flutterwave,
            $this->config,
            $this->customerRepository,
            $this->bankValidationFactory,
            $this->validator,
            $this->rateLimiter,
            $this->logger
        );

        $customer = new CustomerEntity();
        $customer->setId('customer-id');

        $data = new RequestDataBag([
            'bankName' => 'Access Bank',
            'bankCode' => '044',
            'accountNumber' => '0690000032',
            'accountName' => 'Ada Lovelace',
            'bvn' => '12345678901',
        ]);

        $this->customerRepository->expects(static::once())
            ->method('update')
            ->with(static::callback(static function (array $payload): bool {
                $fields = $payload[0]['customFields'];

                return $fields[FlutterwaveConstants::CUSTOMER_FIELD_ACCOUNT_NAME] === 'Ada Lovelace'
                    && $fields[FlutterwaveConstants::CUSTOMER_FIELD_BVN] === '12345678901';
            }));
        $controller->expects(static::once())->method('addFlash')->with('success');
        $controller->method('redirectToRoute')->willReturn($this->createMock(RedirectResponse::class));

        $controller->saveBank($this->sessionRequest(self::VERIFIED), $data, $this->context(), $customer);
    }

    public function testSaveBankOmitsBvnWhenBlank(): void
    {
        $this->enable();
        $this->bankValidationFactory->method('create')->willReturn(new DataValidationDefinition());
        $this->validator->method('getViolations')->willReturn(new ConstraintViolationList());

        $controller = $this->createPartialMock(BankVerificationController::class, ['addFlash', 'redirectToRoute', 'trans']);
        $controller->__construct(
            $this->flutterwave,
            $this->config,
            $this->customerRepository,
            $this->bankValidationFactory,
            $this->validator,
            $this->rateLimiter,
            $this->logger
        );

        $this->customerRepository->expects(static::once())
            ->method('update')
            ->with(static::callback(static fn (array $payload): bool => !array_key_exists(FlutterwaveConstants::CUSTOMER_FIELD_BVN, $payload[0]['customFields'])));
        $controller->method('redirectToRoute')->willReturn($this->createMock(RedirectResponse::class));

        $controller->saveBank($this->sessionRequest(self::VERIFIED), new RequestDataBag([
            'bankName' => 'Access Bank',
            'bankCode' => '044',
            'accountNumber' => '0690000032',
            'accountName' => 'Ada Lovelace',
        ]), $this->context(), (new CustomerEntity())->assign(['id' => 'customer-id']));
    }

    public function testSaveBankFlashesEachViolationAndDoesNotPersist(): void
    {
        $this->enable();
        $this->bankValidationFactory->method('create')->willReturn(new DataValidationDefinition());

        $violation = $this->createMock(ConstraintViolationInterface::class);
        $violation->method('getMessage')->willReturn('Account name is required');
        $this->validator->method('getViolations')->willReturn(new ConstraintViolationList([$violation]));

        $controller = $this->createPartialMock(BankVerificationController::class, ['addFlash', 'redirectToRoute', 'trans']);
        $controller->__construct(
            $this->flutterwave,
            $this->config,
            $this->customerRepository,
            $this->bankValidationFactory,
            $this->validator,
            $this->rateLimiter,
            $this->logger
        );

        $this->customerRepository->expects(static::never())->method('update');
        // One flash for the header, one per violation.
        $controller->expects(static::exactly(2))->method('addFlash');
        $controller->expects(static::once())
            ->method('redirectToRoute')
            ->with('frontend.account.profile.page')
            ->willReturn($this->createMock(RedirectResponse::class));

        $controller->saveBank($this->sessionRequest(), new RequestDataBag(['bankName' => '']), $this->context(), new CustomerEntity());
    }

    public function testVerifyAccountRemembersTheResolvedAccountInTheSession(): void
    {
        $this->enable();
        $this->bank->method('resolveAccount')->willReturn(['status' => 'success', 'data' => ['account_name' => 'Ada Lovelace']]);
        $request = $this->verifyRequest();

        $this->controller()->verifyAccount($request, $this->context());

        static::assertSame(self::VERIFIED, $request->getSession()->get(BankVerificationController::SESSION_VERIFIED_ACCOUNT));
    }

    public function testVerifyAccountReturns429WhenRateLimited(): void
    {
        $this->enable();
        $this->rateLimiter->method('ensureAccepted')->willThrowException(new RateLimitExceededException(time() + 60));

        $this->bank->expects(static::never())->method('resolveAccount');

        static::assertSame(429, $this->controller()->verifyAccount($this->verifyRequest(), $this->context())->getStatusCode());
    }

    /**
     * Without this, a customer could skip verification and store any name
     * against any account number by posting the form directly.
     */
    public function testSaveBankRejectsAnAccountThatWasNotVerified(): void
    {
        $this->enable();
        $this->bankValidationFactory->method('create')->willReturn(new DataValidationDefinition());
        $this->validator->method('getViolations')->willReturn(new ConstraintViolationList());

        $controller = $this->createPartialMock(BankVerificationController::class, ['addFlash', 'redirectToRoute', 'trans']);
        $controller->__construct(
            $this->flutterwave,
            $this->config,
            $this->customerRepository,
            $this->bankValidationFactory,
            $this->validator,
            $this->rateLimiter,
            $this->logger
        );

        $this->customerRepository->expects(static::never())->method('update');
        $controller->expects(static::once())->method('addFlash')->with('danger');
        $controller->method('redirectToRoute')->willReturn($this->createMock(RedirectResponse::class));

        $controller->saveBank($this->sessionRequest(self::VERIFIED), new RequestDataBag([
            'bankName' => 'Access Bank',
            'bankCode' => '044',
            'accountNumber' => '0123456789',
            'accountName' => 'Ada Lovelace',
        ]), $this->context(), (new CustomerEntity())->assign(['id' => 'customer-id']));
    }

    public function testSaveBankStoresTheVerifiedNameNotTheSubmittedOne(): void
    {
        $this->enable();
        $this->bankValidationFactory->method('create')->willReturn(new DataValidationDefinition());
        $this->validator->method('getViolations')->willReturn(new ConstraintViolationList());

        $controller = $this->createPartialMock(BankVerificationController::class, ['addFlash', 'redirectToRoute', 'trans']);
        $controller->__construct(
            $this->flutterwave,
            $this->config,
            $this->customerRepository,
            $this->bankValidationFactory,
            $this->validator,
            $this->rateLimiter,
            $this->logger
        );

        $this->customerRepository->expects(static::once())
            ->method('update')
            ->with(static::callback(static fn (array $payload): bool => $payload[0]['customFields'][FlutterwaveConstants::CUSTOMER_FIELD_ACCOUNT_NAME] === 'Ada Lovelace'));
        $controller->method('redirectToRoute')->willReturn($this->createMock(RedirectResponse::class));

        $controller->saveBank($this->sessionRequest(self::VERIFIED), new RequestDataBag([
            'bankName' => 'Access Bank',
            'bankCode' => '044',
            'accountNumber' => '0690000032',
            'accountName' => 'Someone Else',
        ]), $this->context(), (new CustomerEntity())->assign(['id' => 'customer-id']));
    }
}
