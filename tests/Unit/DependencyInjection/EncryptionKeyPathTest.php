<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\DependencyInjection;

use JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection\JpmMartinSyliusShippingCarriersExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * On a store with Symfony Flex, `composer require` registers the bundle by itself, before anybody has imported the
 * plugin's configuration. Every service the bundle defines exists from that moment, the encrypter among them, and
 * if the path of its key had no default until the configuration was imported, every page of the store would fail
 * on an environment variable nobody had been told to set.
 */
final class EncryptionKeyPathTest extends TestCase
{
    public function testTheKeyHasAPathBeforeThePluginConfigurationIsImported(): void
    {
        $container = new ContainerBuilder();

        (new JpmMartinSyliusShippingCarriersExtension())->load([], $container);

        // Read from the bag: asked by name, a container builder answers an env() parameter with a placeholder.
        self::assertSame(
            '%kernel.project_dir%/config/encryption/jpmmartin_carrier.key',
            $container->getParameterBag()->all()['env(JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH)'] ?? null,
        );
    }
}
