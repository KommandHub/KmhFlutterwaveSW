<?php

declare(strict_types=1);

namespace Kommandhub\FlutterwaveSW\Tests\Unit;

use Kommandhub\FlutterwaveSW\KmhFlutterwaveSW;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;

class KmhFlutterwaveSWTest extends TestCase
{
    private KmhFlutterwaveSW $plugin;
    private ContainerInterface $container;
    private EntityRepository $paymentRepository;
    private EntityRepository $customFieldSetRepository;
    private EntityRepository $customFieldSetRelationRepository;
    private Context $context;

    protected function setUp(): void
    {
        $this->plugin = new KmhFlutterwaveSW(true, '');
        $this->container = $this->createMock(ContainerInterface::class);
        $this->plugin->setContainer($this->container);
        $this->paymentRepository = $this->createMock(EntityRepository::class);
        $this->customFieldSetRepository = $this->createMock(EntityRepository::class);
        $this->customFieldSetRelationRepository = $this->createMock(EntityRepository::class);
        $this->context = Context::createDefaultContext();

        // The custom-field installer treats an empty result as "not yet
        // installed", so install() upserts and addRelations() links.
        $emptyIds = $this->createMock(IdSearchResult::class);
        $emptyIds->method('getIds')->willReturn([]);
        $emptyIds->method('getTotal')->willReturn(0);
        $this->customFieldSetRepository->method('searchIds')->willReturn($emptyIds);
        $this->customFieldSetRelationRepository->method('searchIds')->willReturn($emptyIds);
    }

    /**
     * Container services every lifecycle hook may resolve. Kept in one place so
     * each test's willReturnMap stays a single source of truth.
     *
     * @return array<int, array{0: string, 1: object}>
     */
    private function containerMap(PluginIdProvider $pluginIdProvider): array
    {
        return [
            ['payment_method.repository', $this->paymentRepository],
            [PluginIdProvider::class, $pluginIdProvider],
            ['custom_field_set.repository', $this->customFieldSetRepository],
            ['custom_field_set_relation.repository', $this->customFieldSetRelationRepository],
        ];
    }

    /**
     * The plugin's own require is shopware/core and shopware/storefront, both
     * always present, so nothing here needs Composer invoked on install or
     * uninstall.
     */
    public function testExecuteComposerCommandsIsDisabled(): void
    {
        $this->assertFalse($this->plugin->executeComposerCommands());
    }

    public function testInstallAddsPaymentMethod(): void
    {
        $installContext = $this->createMock(InstallContext::class);
        $installContext->method('getContext')->willReturn($this->context);

        $pluginIdProvider = $this->createMock(PluginIdProvider::class);

        $this->container->method('get')->willReturnMap($this->containerMap($pluginIdProvider));

        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn(null);
        $this->paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $this->paymentRepository->expects($this->once())->method('create');

        $this->plugin->install($installContext);
    }

    public function testUpdate(): void
    {
        $updateContext = $this->createMock(UpdateContext::class);
        $updateContext->method('getContext')->willReturn($this->context);

        $pluginIdProvider = $this->createMock(PluginIdProvider::class);

        $this->container->method('get')->willReturnMap($this->containerMap($pluginIdProvider));

        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('payment-id');
        $this->paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $this->paymentRepository->expects($this->once())->method('update');

        $this->plugin->update($updateContext);
    }

    public function testActivate(): void
    {
        $activateContext = $this->createMock(ActivateContext::class);
        $activateContext->method('getContext')->willReturn($this->context);

        $this->container->method('get')->willReturnMap($this->containerMap($this->createMock(PluginIdProvider::class)));

        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('payment-id');
        $this->paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $this->paymentRepository->expects($this->once())
            ->method('update')
            ->with([['id' => 'payment-id', 'active' => true]], $this->context);

        $this->plugin->activate($activateContext);
    }

    public function testDeactivate(): void
    {
        $deactivateContext = $this->createMock(DeactivateContext::class);
        $deactivateContext->method('getContext')->willReturn($this->context);

        $this->container->method('get')->willReturnMap($this->containerMap($this->createMock(PluginIdProvider::class)));

        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('payment-id');
        $this->paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $this->paymentRepository->expects($this->once())
            ->method('update')
            ->with([['id' => 'payment-id', 'active' => false]], $this->context);

        $this->plugin->deactivate($deactivateContext);
    }

    public function testUninstallDeactivatesPaymentMethod(): void
    {
        $uninstallContext = $this->createMock(UninstallContext::class);
        $uninstallContext->method('getContext')->willReturn($this->context);
        $uninstallContext->method('keepUserData')->willReturn(true);

        $this->container->method('get')->willReturnMap($this->containerMap($this->createMock(PluginIdProvider::class)));

        $idSearchResult = $this->createMock(IdSearchResult::class);
        $idSearchResult->method('firstId')->willReturn('payment-id');
        $this->paymentRepository->method('searchIds')->willReturn($idSearchResult);

        $this->paymentRepository->expects($this->once())
            ->method('update')
            ->with([['id' => 'payment-id', 'active' => false]], $this->context);

        $this->plugin->uninstall($uninstallContext);
    }

    public function testUninstallRemovesCustomFieldsWhenNotKeepingData(): void
    {
        // Self-contained: the fieldset repository must report an existing
        // fieldset so uninstall deletes it, which the shared setUp deliberately
        // stubs empty for the install-path tests.
        $plugin = new KmhFlutterwaveSW(true, '');
        $container = $this->createMock(ContainerInterface::class);
        $plugin->setContainer($container);

        $paymentRepository = $this->createMock(EntityRepository::class);
        $paymentIds = $this->createMock(IdSearchResult::class);
        $paymentIds->method('firstId')->willReturn('payment-id');
        $paymentRepository->method('searchIds')->willReturn($paymentIds);

        $fieldsetRepository = $this->createMock(EntityRepository::class);
        $fieldsetIds = $this->createMock(IdSearchResult::class);
        $fieldsetIds->method('getIds')->willReturn(['fieldset-id']);
        $fieldsetRepository->method('searchIds')->willReturn($fieldsetIds);

        $container->method('get')->willReturnMap([
            ['payment_method.repository', $paymentRepository],
            [PluginIdProvider::class, $this->createMock(PluginIdProvider::class)],
            ['custom_field_set.repository', $fieldsetRepository],
            ['custom_field_set_relation.repository', $this->createMock(EntityRepository::class)],
        ]);

        $uninstallContext = $this->createMock(UninstallContext::class);
        $uninstallContext->method('getContext')->willReturn($this->context);
        $uninstallContext->method('keepUserData')->willReturn(false);

        $fieldsetRepository->expects($this->once())->method('delete');

        $plugin->uninstall($uninstallContext);
    }

    public function testAddPaymentMethodSkippedIfNoContainer(): void
    {
        $plugin = new KmhFlutterwaveSW(true, '');
        $installContext = $this->createMock(InstallContext::class);
        $installContext->method('getContext')->willReturn($this->context);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Typed property Symfony\Component\HttpKernel\Bundle\Bundle::$container must not be accessed before initialization');

        $plugin->install($installContext);
    }

    public function testUninstallSkippedIfNoContainer(): void
    {
        $plugin = new KmhFlutterwaveSW(true, '');
        $uninstallContext = $this->createMock(UninstallContext::class);
        $uninstallContext->method('getContext')->willReturn($this->context);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Typed property Symfony\Component\HttpKernel\Bundle\Bundle::$container must not be accessed before initialization');

        $plugin->uninstall($uninstallContext);
    }
}
