<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit\Checkout\Cart\Validation;

use Kommandhub\FlutterwaveSW\Checkout\Cart\Error\ConfigurationError;
use Kommandhub\FlutterwaveSW\Checkout\Cart\Validation\TransactionCartValidator;
use Kommandhub\FlutterwaveSW\Checkout\Payment\FlutterwavePaymentHandler;
use Kommandhub\FlutterwaveSW\Setting\Service\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class TransactionCartValidatorTest extends TestCase
{
    private TransactionCartValidator $validator;

    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->validator = new TransactionCartValidator($this->config);
    }

    public function testValidateSkipsIfDifferentPaymentMethod(): void
    {
        $cart = $this->createMock(Cart::class);
        $errors = new ErrorCollection();
        $context = $this->createMock(SalesChannelContext::class);
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setHandlerIdentifier('SomeOtherHandler');

        $context->method('getPaymentMethod')->willReturn($paymentMethod);

        $this->validator->validate($cart, $errors, $context);
        $this->assertCount(0, $errors);
    }

    public function testValidateBlocksZeroValueCart(): void
    {
        $this->config->method('getSecretKey')->willReturn('test-secret-key');

        $cart = new Cart('test');
        $cart->setLineItems(new LineItemCollection([new LineItem('id', 'type')]));
        $cart->setPrice(new CartPrice(0, 0, 0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

        $errors = new ErrorCollection();
        $context = $this->createMock(SalesChannelContext::class);
        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getHandlerIdentifier')->willReturn(FlutterwavePaymentHandler::class);
        $paymentMethod->method('getTranslation')->with('name')->willReturn('Flutterwave');

        $context->method('getPaymentMethod')->willReturn($paymentMethod);

        $this->validator->validate($cart, $errors, $context);

        $this->assertCount(1, $errors);
        $this->assertInstanceOf(PaymentMethodBlockedError::class, $errors->first());
    }

    public function testValidateDoesNotBlockNonZeroValueCart(): void
    {
        $this->config->method('getSecretKey')->willReturn('test-secret-key');

        $cart = new Cart('test');
        $cart->setLineItems(new LineItemCollection([new LineItem('id', 'type')]));
        $cart->setPrice(new CartPrice(10, 10, 10, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

        $errors = new ErrorCollection();
        $context = $this->createMock(SalesChannelContext::class);
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setHandlerIdentifier(FlutterwavePaymentHandler::class);

        $context->method('getPaymentMethod')->willReturn($paymentMethod);

        $this->validator->validate($cart, $errors, $context);

        $this->assertCount(0, $errors);
    }

    public function testValidateBlocksIfConfigurationMissing(): void
    {
        $this->config->method('getSecretKey')->willReturn('');

        $cart = new Cart('test');
        $cart->setLineItems(new LineItemCollection([new LineItem('id', 'type')]));
        $cart->setPrice(new CartPrice(10, 10, 10, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

        $errors = new ErrorCollection();
        $context = $this->createMock(SalesChannelContext::class);
        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getHandlerIdentifier')->willReturn(FlutterwavePaymentHandler::class);

        $context->method('getPaymentMethod')->willReturn($paymentMethod);

        $this->validator->validate($cart, $errors, $context);

        $this->assertCount(1, $errors);
        $this->assertInstanceOf(ConfigurationError::class, $errors->first());
    }

    /**
     * @return iterable<string, array{string, bool, int}>
     */
    public static function keyModeProvider(): iterable
    {
        yield 'test key in live mode' => ['FLWSECK_TEST-abc-X', false, 1];
        yield 'live key in sandbox mode' => ['FLWSECK-abc-X', true, 1];
        yield 'non-key value in sandbox mode' => ['5c446e98e0e79b31cc4abf14076c028a', true, 1];
        yield 'test key in sandbox mode' => ['FLWSECK_TEST-abc-X', true, 0];
        yield 'live key in live mode' => ['FLWSECK-abc-X', false, 0];
    }

    #[DataProvider('keyModeProvider')]
    public function testValidateBlocksKeyThatDoesNotMatchMode(string $secretKey, bool $sandbox, int $expectedErrors): void
    {
        $this->config->method('getSecretKey')->willReturn($secretKey);
        $this->config->method('isSandbox')->willReturn($sandbox);

        $cart = new Cart('test');
        $cart->setLineItems(new LineItemCollection([new LineItem('id', 'type')]));
        $cart->setPrice(new CartPrice(10, 10, 10, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

        $errors = new ErrorCollection();
        $context = $this->createMock(SalesChannelContext::class);
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setHandlerIdentifier(FlutterwavePaymentHandler::class);
        $context->method('getPaymentMethod')->willReturn($paymentMethod);

        $this->validator->validate($cart, $errors, $context);

        $this->assertCount($expectedErrors, $errors);
    }

    public function testIsZeroValueCartWithEmptyCart(): void
    {
        $cart = new Cart('test');
        $cart->setLineItems(new LineItemCollection());

        $this->assertFalse($this->validator->isZeroValueCart($cart));
    }

    public function testIsZeroValueCartWithItemsAndPositivePrice(): void
    {
        $cart = new Cart('test');
        $cart->setLineItems(new LineItemCollection([new LineItem('id', 'type')]));
        $cart->setPrice(new CartPrice(10, 10, 10, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

        $this->assertFalse($this->validator->isZeroValueCart($cart));
    }

    public function testIsZeroValueCartWithItemsAndZeroPrice(): void
    {
        $cart = new Cart('test');
        $cart->setLineItems(new LineItemCollection([new LineItem('id', 'type')]));
        $cart->setPrice(new CartPrice(0, 0, 0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

        $this->assertTrue($this->validator->isZeroValueCart($cart));
    }
}
