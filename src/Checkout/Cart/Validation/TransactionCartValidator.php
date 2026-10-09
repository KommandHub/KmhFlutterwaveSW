<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Checkout\Cart\Validation;

use Kommandhub\FlutterwaveSW\Checkout\Cart\Error\ConfigurationError;
use Kommandhub\FlutterwaveSW\Checkout\Payment\FlutterwavePaymentHandler;
use Kommandhub\FlutterwaveSW\Setting\Service\Config;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Validates the cart for the Standard Payment method.
 *
 * This validator checks if the selected payment method is the StandardPaymentHandler.
 * If so, it uses the CartPriceService to determine if the cart has a zero value.
 * If the cart value is zero, it adds a PaymentMethodBlockedError to the error collection,
 * preventing the use of this payment method for zero-value carts.
 */
#[AutoconfigureTag('shopware.cart.validator')]
class TransactionCartValidator implements CartValidatorInterface
{
    private const TEST_KEY_PREFIX = 'FLWSECK_TEST-';

    public function __construct(private readonly Config $config)
    {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        // Check if the selected payment method is not the FlutterwavePaymentHandler; if so, skip validation.
        if ($context->getPaymentMethod()->getHandlerIdentifier() !== FlutterwavePaymentHandler::class) {
            return;
        }

        $salesChannelId = $context->getSalesChannelId();
        $secretKey = $this->config->getSecretKey($salesChannelId);

        // A key that does not match the mode is as unusable as a missing one,
        // and dangerous both ways: a test key in live mode marks orders paid
        // for mock card payments; a live key in sandbox mode takes real money
        // while the merchant believes they are testing.
        $isTestKey = str_starts_with($secretKey, self::TEST_KEY_PREFIX);

        if ($secretKey === '' || $isTestKey !== $this->config->isSandbox($salesChannelId)) {
            $errors->add(new ConfigurationError());
        }

        // If the cart value is zero, add a PaymentMethodBlockedError to prevent using this payment method.
        if ($this->isZeroValueCart($cart)) {
            $paymentMethod = $context->getPaymentMethod();
            /** @var string $name */
            $name = $paymentMethod->getTranslation('name') ?? '';

            // Shopware 6.8 reorders this constructor to ($id, $name, $reason).
            // The plugin currently targets 6.6/6.7, whose signature is
            // ($name, $reason, $id) — the order used here. Reordering now would
            // pass wrong values on the supported range, so the deprecation is
            // suppressed and the reorder deferred until 6.8 is added to the
            // supported versions.
            // @phpstan-ignore method.deprecated
            $errors->add(new PaymentMethodBlockedError($name, 'Zero value cart', $paymentMethod->getId()));
        }
    }

    /**
     * Determines if the cart has items but the total price is zero or less.
     *
     * @param Cart $cart The cart to check.
     *
     * @return bool True if the cart has items and the total price is zero or less, false otherwise.
     */
    public function isZeroValueCart(Cart $cart): bool
    {
        // Return false if the cart has no items.
        if ($cart->getLineItems()->count() === 0) {
            return false;
        }

        // Return false if the cart's total price is greater than zero.
        if ($cart->getPrice()->getTotalPrice() > 0) {
            return false;
        }

        // Return true if the cart has items and the total price is zero or less.
        return true;
    }
}
