<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\DependencyInjection;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RateLifetimeTest extends KernelTestCase
{
    /**
     * An application that configures nothing quotes a rate for 15 minutes and keeps it for 24 hours.
     */
    public function testAnApplicationWithoutConfigurationQuotesForFifteenMinutesAndKeepsForADay(): void
    {
        self::bootKernel();

        self::assertSame(900, self::getContainer()->getParameter('jpmmartin_carrier.rate_lifetime'));
        self::assertSame(86400, self::getContainer()->getParameter('jpmmartin_carrier.rate_retention'));
    }
}
