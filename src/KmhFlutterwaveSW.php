<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW;

use Kommandhub\FlutterwaveSW\Installer\CustomFieldsInstaller;
use Kommandhub\FlutterwaveSW\Installer\PaymentMethodInstaller;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

class KmhFlutterwaveSW extends Plugin
{
    /**
     * This plugin's own runtime dependencies (composer.json `require`) are
     * `shopware/core` and `shopware/storefront` — both guaranteed present in any
     * Shopware installation already. There is nothing here for Shopware to
     * `composer require`/`remove` on install or uninstall, so this returns
     * false rather than true.
     *
     * Returning true (the previous value) made every install/activate/
     * deactivate/uninstall shell out to Composer for no reason — needless work
     * and risk in a real shop, and the direct cause of a reproducible dev-loop
     * flake: Shopware's test bootstrapper force-reinstalls the plugin on every
     * run, which ran `composer remove` for it, wiping its entry — and PSR-4
     * mapping — from the root project's composer.json between test runs. That
     * surfaced as "Class KmhFlutterwaveSW not found" on whichever run
     * followed a reinstall, or, when the class had already been autoloaded from
     * elsewhere in the same process, as this file silently reporting 0%
     * coverage despite being fully exercised.
     */
    public function executeComposerCommands(): bool
    {
        return false;
    }

    /**
     * Shopware loads `Resources/config/packages/*` only for bundles that opt in
     * here; the plugin ships its bank-verification rate limiter there.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->buildDefaultConfig($container);
    }

    public function install(InstallContext $installContext): void
    {
        $this->getPaymentMethodInstaller()->install(static::class, $installContext->getContext());
        $this->installCustomFields($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->getPaymentMethodInstaller()->install(static::class, $updateContext->getContext());
        $this->installCustomFields($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        $this->getPaymentMethodInstaller()->deactivate($uninstallContext->getContext());

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Drop the bank-profile custom fields when the merchant opts out of
        // keeping plugin data. Customer records keep their values otherwise.
        $this->getCustomFieldsInstaller()->uninstall($uninstallContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->getPaymentMethodInstaller()->activate($activateContext->getContext());
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->getPaymentMethodInstaller()->deactivate($deactivateContext->getContext());
        parent::deactivate($deactivateContext);
    }

    private function getPaymentMethodInstaller(): PaymentMethodInstaller
    {
        // Plugin::$container is declared nullable, but every lifecycle hook that
        // reaches here runs after Shopware has set it. Asserting keeps the
        // failure loud (as the uninitialised-container tests expect) instead of
        // inventing a silent no-op path that could never be exercised.
        $container = $this->container;
        \assert($container instanceof ContainerInterface);

        /** @var EntityRepository<PaymentMethodCollection> $paymentMethodRepo */
        $paymentMethodRepo = $container->get('payment_method.repository');

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $container->get(PluginIdProvider::class);

        return new PaymentMethodInstaller(
            $paymentMethodRepo,
            $pluginIdProvider
        );
    }

    private function installCustomFields(Context $context): void
    {
        $installer = $this->getCustomFieldsInstaller();
        $installer->install($context);
        $installer->addRelations($context);
    }

    private function getCustomFieldsInstaller(): CustomFieldsInstaller
    {
        $container = $this->container;
        \assert($container instanceof ContainerInterface);

        /** @var EntityRepository $customFieldSetRepository */
        $customFieldSetRepository = $container->get('custom_field_set.repository');

        /** @var EntityRepository $customFieldSetRelationRepository */
        $customFieldSetRelationRepository = $container->get('custom_field_set_relation.repository');

        return new CustomFieldsInstaller(
            $customFieldSetRepository,
            $customFieldSetRelationRepository
        );
    }
}
