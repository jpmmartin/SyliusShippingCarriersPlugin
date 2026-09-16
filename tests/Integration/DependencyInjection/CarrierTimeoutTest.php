<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\DependencyInjection;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CarrierTimeoutTest extends KernelTestCase
{
    /**
     * CA-47: an application that configures nothing waits 10 seconds at most for a carrier.
     */
    public function testAnApplicationWithoutConfigurationWaitsTenSecondsAtMost(): void
    {
        self::bootKernel();

        self::assertSame(10.0, self::getContainer()->getParameter('jpmmartin_carrier.carrier_timeout'));
    }
}
