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
     * Without configuration, a rate is quoted for 15 minutes and kept as the last known rate for 24 hours.
     */
    public function testRatesAreQuotedForFifteenMinutesAndKeptForADayByDefault(): void
    {
        $config = $this->process([]);

        self::assertSame(900, $config['rate_lifetime']);
        self::assertSame(86400, $config['rate_retention']);
    }

    public function testTheLifetimeAndTheRetentionOfRatesCanBeConfigured(): void
    {
        $config = $this->process([['rate_lifetime' => 300, 'rate_retention' => 3600]]);

        self::assertSame(300, $config['rate_lifetime']);
        self::assertSame(3600, $config['rate_retention']);
    }

    /**
     * Without configuration, the status of a shipment is kept for 5 minutes.
     */
    public function testTheStatusOfAShipmentIsKeptForFiveMinutesByDefault(): void
    {
        self::assertSame(300, $this->process([])['tracking_lifetime']);
    }

    public function testTheLifetimeOfTheStatusOfAShipmentCanBeConfigured(): void
    {
        self::assertSame(60, $this->process([['tracking_lifetime' => 60]])['tracking_lifetime']);
    }

    public function testATrackingLifetimeOfZeroIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['tracking_lifetime' => 0]]);
    }

    /**
     * A rate would be gone before it expired, and there would never be a last known rate.
     */
    public function testARetentionShorterThanTheLifetimeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['rate_lifetime' => 3600, 'rate_retention' => 300]]);
    }

    public function testARateLifetimeOfZeroIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['rate_lifetime' => 0]]);
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
