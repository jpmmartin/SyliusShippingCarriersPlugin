<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Tracking;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;

final class TrackingCachePoolTest extends KernelTestCase
{
    /**
     * Without configuration, the status of a shipment is kept on the filesystem: no Redis or other service is
     * required to know where an order is.
     */
    public function testTheStatusOfShipmentsIsKeptOnTheFilesystemByDefault(): void
    {
        self::bootKernel();

        $pool = self::getContainer()->get('jpmmartin_carrier.cache.tracking');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        // The debug container wraps every pool to trace it.
        self::assertInstanceOf(FilesystemAdapter::class, $pool instanceof TraceableAdapter ? $pool->getPool() : $pool);
    }

    /**
     * The status of a shipment and the rates never share a pool: one is kept for minutes and the other for a day,
     * and an application that empties one must not lose the other.
     */
    public function testTheStatusOfShipmentsIsKeptApartFromTheRates(): void
    {
        self::bootKernel();

        self::assertNotSame(
            self::getContainer()->get('jpmmartin_carrier.cache.tracking'),
            self::getContainer()->get('jpmmartin_carrier.cache.rates'),
        );
    }

    /**
     * Guards the lifetime the pool is given, which is what makes a second look at the same order cost no enquiry.
     */
    public function testAnApplicationWithoutConfigurationKeepsTheStatusForFiveMinutes(): void
    {
        self::bootKernel();

        self::assertSame(300, self::getContainer()->getParameter('jpmmartin_carrier.tracking_lifetime'));
    }
}
