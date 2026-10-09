<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Checkout\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Class ConfigurationError.
 *
 * Represents an error when the Flutterwave plugin is not correctly configured.
 * This error blocks the order process and is persistent across requests.
 */
class ConfigurationError extends Error
{
    /**
     * Unique identifier for the error.
     */
    public const KEY = 'flutterwave-configuration-error';

    /**
     * Returns the unique ID for this error.
     *
     * @return string
     */
    public function getId(): string
    {
        return self::KEY;
    }

    /**
     * Returns the translation key for the error message.
     *
     * @return string
     */
    public function getMessageKey(): string
    {
        return 'flutterwaveConfigurationError';
    }

    /**
     * Indicates that the error should be persisted in the session.
     *
     * @return bool
     */
    public function isPersistent(): bool
    {
        return true;
    }

    /**
     * Returns the severity level of the error.
     *
     * @return int
     */
    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    /**
     * Indicates that this error should block the order from being placed.
     *
     * @return bool
     */
    public function blockOrder(): bool
    {
        return true;
    }

    /**
     * Returns parameters for the translation message.
     *
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [];
    }
}
