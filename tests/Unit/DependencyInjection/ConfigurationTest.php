<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\DependencyInjection;

use JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    /**
     * Without configuration, a request to a carrier may take 10 seconds.
     */
    public function testTheCarrierTimeoutIsTenSecondsByDefault(): void
    {
        self::assertSame(10.0, $this->process([])['carrier_timeout']);
    }

    public function testTheCarrierTimeoutCanBeConfigured(): void
    {
        self::assertSame(3.5, $this->process([['carrier_timeout' => 3.5]])['carrier_timeout']);
    }

    public function testACarrierTimeoutOfZeroIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['carrier_timeout' => 0]]);
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private function process(array $configs): array
    {
        return (new Processor())->processConfiguration(new Configuration(), $configs);
    }
}
