<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Rate;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;

/**
 * The `rate_cache_in_memory` environment is configured in tests/TestApplication/config/config.yaml.
 */
final class RateCachePoolTest extends KernelTestCase
{
    /**
     * Without configuration, rates are kept on the filesystem: no Redis or other service is required.
     */
    public function testRatesAreKeptOnTheFilesystemByDefault(): void
    {
        self::bootKernel();

        self::assertInstanceOf(FilesystemAdapter::class, $this->ratePool());
    }

    /**
     * An application keeps the rates elsewhere from its own configuration, without touching the plugin.
     */
    public function testAnApplicationPointsTheRateCacheElsewhere(): void
    {
        self::bootKernel(['environment' => 'rate_cache_in_memory']);

        self::assertInstanceOf(ArrayAdapter::class, $this->ratePool());
    }

    private function ratePool(): CacheItemPoolInterface
    {
        $pool = self::getContainer()->get('jpmmartin_carrier.cache.rates');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        // The debug container wraps every pool to trace it.
        return $pool instanceof TraceableAdapter ? $pool->getPool() : $pool;
    }
}
