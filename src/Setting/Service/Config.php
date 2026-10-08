<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Setting\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class Config
{
    public const KEY = 'KmhFlutterwaveSW.config.';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    /**
     * Retrieves a configuration value by key.
     *
     * @param string $key Configuration key (without prefix).
     * @param array|bool|float|int|string|null $default Default value if config is not set.
     * @param string|null $salesChannelId Optional sales channel ID.
     *
     * @return array|bool|float|int|string|null The configuration value or default.
     */
    public function get(string $key, array|bool|float|int|string|null $default = null, ?string $salesChannelId = null): array|bool|float|int|string|null
    {
        $value = $this->systemConfigService->get(self::KEY . $key, $salesChannelId);

        return $value ?? $default;
    }

    public function getString(string $key, ?string $salesChannelId = null): string
    {
        $value = $this->systemConfigService->get(self::KEY . $key, $salesChannelId);

        if (!is_string($value)) {
            return '';
        }

        return $value;
    }

    public function getBool(string $key, ?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(self::KEY . $key, $salesChannelId);
    }

    public function getInt(string $key, ?string $salesChannelId = null): int
    {
        return $this->systemConfigService->getInt(self::KEY . $key, $salesChannelId);
    }

    public function getArray(string $key, ?string $salesChannelId = null): array
    {
        $value = $this->systemConfigService->get(self::KEY . $key, $salesChannelId);

        if (!is_array($value)) {
            return [];
        }

        return $value;
    }

    /**
     * Flutterwave has no separate sandbox host: test mode is selected purely by
     * using test credentials (`FLWSECK_TEST-…`) against the same base URL.
     */
    public function isSandbox(?string $salesChannelId = null): bool
    {
        return $this->getBool('enableSandbox', $salesChannelId);
    }

    public function getSecretKey(?string $salesChannelId = null): string
    {
        return $this->isSandbox($salesChannelId)
            ? $this->getString('apiSecretKeySandbox', $salesChannelId)
            : $this->getString('apiSecretKey', $salesChannelId);
    }

    public function getPublicKey(?string $salesChannelId = null): string
    {
        return $this->isSandbox($salesChannelId)
            ? $this->getString('apiPublicKeySandbox', $salesChannelId)
            : $this->getString('apiPublicKey', $salesChannelId);
    }

    /**
     * The webhook secret hash configured in the Flutterwave dashboard.
     *
     * This is a value the merchant chooses and echoes into both the dashboard and
     * this setting; it is NOT derived from the API keys, which is why Flutterwave
     * needs a dedicated field where Paystack reuses the secret key.
     *
     * @see https://developer.flutterwave.com/v3.0/docs/webhooks
     */
    public function getSecretHash(?string $salesChannelId = null): string
    {
        return $this->isSandbox($salesChannelId)
            ? $this->getString('secretHashSandbox', $salesChannelId)
            : $this->getString('secretHash', $salesChannelId);
    }

    /**
     * The payment method title shown to the customer on Flutterwave's hosted
     * checkout page.
     */
    public function getTitle(?string $salesChannelId = null): string
    {
        return $this->getString('title', $salesChannelId) ?: 'Pay with Flutterwave';
    }

    /**
     * The payment method description shown to the customer on Flutterwave's
     * hosted checkout page.
     */
    public function getDescription(?string $salesChannelId = null): string
    {
        return $this->getString('description', $salesChannelId) ?: 'Developed with ❤️ by Kommandhub';
    }

    /**
     * The logo URL for Flutterwave's checkout customisation, or '' when unset.
     *
     * Calls SystemConfigService::getString() directly rather than through this
     * class's own getString() helper. Both coerce an unset value to '' — the
     * underlying service casts with `(string) $value`, so it can never actually
     * return null despite an older nullable signature this replaces having
     * suggested otherwise — but going direct keeps this method's behaviour
     * pinned to Shopware's own contract rather than an extra layer of coercion.
     */
    public function getLogo(?string $salesChannelId = null): string
    {
        return $this->systemConfigService->getString(self::KEY . 'logo', $salesChannelId);
    }
}
