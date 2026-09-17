<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Packaging;

use JpmMartin\SyliusShippingCarriersPlugin\Packaging\DefaultPackagingStrategy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The `packaging_strategy_replaced` environment is configured in tests/TestApplication/config/config.yaml.
 */
final class PackagingStrategyReplacementTest extends KernelTestCase
{
    public function testTheStrategyThePluginShipsWithIsTheOneUsedByDefault(): void
    {
        self::bootKernel();

        self::assertInstanceOf(DefaultPackagingStrategy::class, self::getContainer()->get('jpmmartin_carrier.packaging_strategy'));
    }

    /**
     * A store that packs its shipments its own way points the alias at its own service, without touching the
     * plugin. Everything that packs goes through that alias, so nothing is left on the old strategy.
     */
    public function testAnApplicationReplacesTheStrategyFromItsOwnConfiguration(): void
    {
        self::bootKernel(['environment' => 'packaging_strategy_replaced']);

        self::assertInstanceOf(AnotherPackagingStrategy::class, self::getContainer()->get('jpmmartin_carrier.packaging_strategy'));
    }
}
