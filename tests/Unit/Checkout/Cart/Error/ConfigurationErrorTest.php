<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit\Checkout\Cart\Error;

use Kommandhub\FlutterwaveSW\Checkout\Cart\Error\ConfigurationError;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Error\Error;

class ConfigurationErrorTest extends TestCase
{
    public function testConfigurationErrorProperties(): void
    {
        $error = new ConfigurationError();

        $this->assertSame('flutterwave-configuration-error', $error->getId());
        $this->assertSame('flutterwaveConfigurationError', $error->getMessageKey());
        $this->assertTrue($error->isPersistent());
        $this->assertSame(Error::LEVEL_ERROR, $error->getLevel());
        $this->assertTrue($error->blockOrder());
        $this->assertIsArray($error->getParameters());
        $this->assertEmpty($error->getParameters());
    }
}
