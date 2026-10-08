<?php

declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$loader = (new TestBootstrapper())
    ->setPlatformEmbedded(true)
    ->addCallingPlugin()
    ->addActivePlugins('KmhFlutterwaveSW')
    ->setForceInstallPlugins(true)
    ->bootstrap()
    ->getClassLoader();

/*
 * Register the plugin's own namespace explicitly.
 *
 * The plugin declares executeComposerCommands() === true, so Shopware runs
 * `composer remove` for it during the uninstall half of the force-install cycle
 * above. That strips the plugin from the *root* composer.json and its PSR-4
 * mapping from the root autoloader, so the following run cannot autoload src/
 * and every test dies with "Class ... not found" regardless of the code under
 * test. Mapping the namespace here keeps the suite deterministic instead of
 * dependent on whichever composer state the previous run left behind.
 */
$loader->addPsr4('Kommandhub\\FlutterwaveSW\\', \dirname(__DIR__) . '/src');
$loader->addPsr4('Kommandhub\\FlutterwaveSW\\Tests\\', __DIR__);
