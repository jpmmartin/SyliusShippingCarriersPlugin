<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Rate;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateCacheKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateCacheKeyTest extends TestCase
{
    /**
     * Nothing about the buyer takes part: two buyers sending the same packages to the same place share the rates.
     */
    public function testTwoBuyersWithTheSameDestinationAndPackagesShareTheKey(): void
    {
        $firstBuyer = $this->key();
        $secondBuyer = $this->key(destination: new Address('US', '98101', 'Seattle', '1400 Pine St', 'WA'));

        self::assertSame($firstBuyer, $secondBuyer);
    }

    public function testTheKeyIsAValidCacheKey(): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]{1,64}$/', $this->key());
    }

    /**
     * @param array{0?: string, 1?: array<string, string|null>, 2?: Address, 3?: Address, 4?: Package, 5?: string} $changed
     */
    #[DataProvider('changesThatAskAgain')]
    public function testWhatChangesThePriceChangesTheKey(array $changed): void
    {
        self::assertNotSame($this->key(), $this->key(...$changed));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function changesThatAskAgain(): iterable
    {
        yield 'another carrier' => [['carrier' => 'fedex']];
        yield 'another pickup type' => [['carrierConfiguration' => ['environment' => 'sandbox', 'pickup_type' => 'drop_off', 'account_number' => 'A1B2C3']]];
        yield 'another account' => [['carrierConfiguration' => ['environment' => 'sandbox', 'pickup_type' => 'scheduled', 'account_number' => null]]];
        yield 'an edited origin' => [['origin' => new Address('US', '60601', 'Chicago', '2 Main St', 'IL')]];
        yield 'another postcode' => [['destination' => new Address('US', '98109', 'Seattle', '500 Pine St', 'WA')]];
        yield 'a home instead of a business' => [['destination' => new Address('US', '98101', 'Seattle', '500 Pine St', 'WA', residential: true)]];
        yield 'a heavier package' => [['package' => new Package('Medium', 13.0, 11.0, 9.0, 'in', 6.0, 'lb', [])]];
        yield 'a package in another box' => [['package' => new Package('Large', 20.0, 11.0, 9.0, 'in', 5.5, 'lb', [])]];
        yield 'another currency' => [['currencyCode' => 'EUR']];
    }

    public function testTheOrderOfTheCarrierConfigurationDoesNotMatter(): void
    {
        self::assertSame(
            $this->key(carrierConfiguration: ['pickup_type' => 'scheduled', 'account_number' => 'A1B2C3', 'environment' => 'sandbox']),
            $this->key(),
        );
    }

    /**
     * Each channel keeps rates for as long as it says, so none of them shares a stored rate with another.
     */
    public function testTwoChannelsDoNotShareARate(): void
    {
        self::assertNotSame($this->key(channelCode: 'WEB'), $this->key(channelCode: 'MOBILE'));
        self::assertSame($this->key(channelCode: 'WEB'), $this->key(channelCode: 'WEB'));
    }

    /**
     * @param array<string, string|null>|null $carrierConfiguration
     */
    private function key(
        string $carrier = 'ups',
        ?array $carrierConfiguration = null,
        ?Address $origin = null,
        ?Address $destination = null,
        ?Package $package = null,
        string $currencyCode = 'USD',
        ?string $channelCode = 'WEB',
    ): string {
        return RateCacheKey::for(
            $carrier,
            $carrierConfiguration ?? ['environment' => 'sandbox', 'pickup_type' => 'scheduled', 'account_number' => 'A1B2C3'],
            new RateRequest(
                $origin ?? new Address('US', '60601', 'Chicago', '1 Main St', 'IL'),
                $destination ?? new Address('US', '98101', 'Seattle', '500 Pine St', 'WA'),
                [$package ?? new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', [])],
            ),
            $currencyCode,
            $channelCode,
        );
    }
}
