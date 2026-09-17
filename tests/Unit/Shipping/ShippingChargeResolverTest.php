<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Shipping;

use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingCharge;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;

/**
 * One test per way a carrier's shipping method is charged, or not offered at all.
 */
final class ShippingChargeResolverTest extends TestCase
{
    private const FLAT = ['service' => '03', 'failure_policy' => 'flat', 'flat_amount' => ['WEB_US' => 1200, 'WEB_EU' => 1100]];

    private const HIDE = ['service' => '03', 'failure_policy' => 'hide'];

    public function testAFreshRateIsCharged(): void
    {
        self::assertEquals(
            new ShippingCharge(1540, ShippingCharge::SOURCE_RATE),
            $this->resolve(RateResult::quoted(new Rate('03', 1540, 'USD')), self::FLAT),
        );
    }

    /**
     * The last known rate comes before the policy, whichever it is.
     *
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('policies')]
    public function testWhenTheCarrierFailsTheLastKnownRateIsCharged(array $configuration): void
    {
        self::assertEquals(
            new ShippingCharge(1490, ShippingCharge::SOURCE_LAST_KNOWN),
            $this->resolve(RateResult::carrierFailed(new Rate('03', 1490, 'USD')), $configuration),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function policies(): iterable
    {
        yield 'hide' => [self::HIDE];
        yield 'flat' => [self::FLAT];
    }

    public function testWhenTheCarrierFailsWithoutALastKnownRateTheFlatAmountOfTheChannelIsCharged(): void
    {
        self::assertEquals(
            new ShippingCharge(1100, ShippingCharge::SOURCE_FLAT),
            $this->resolve(RateResult::carrierFailed(null), self::FLAT, 'WEB_EU'),
        );
    }

    public function testWhenTheCarrierFailsWithoutALastKnownRateAHiddenMethodIsUnavailable(): void
    {
        self::assertNull($this->resolve(RateResult::carrierFailed(null), self::HIDE));
    }

    /**
     * Without a policy, the shipping method hides.
     */
    public function testAMethodWithoutAPolicyHides(): void
    {
        self::assertNull($this->resolve(RateResult::carrierFailed(null), ['service' => '03', 'flat_amount' => ['WEB_US' => 1200]]));
    }

    public function testAFlatMethodWithoutAnAmountForTheChannelIsUnavailable(): void
    {
        self::assertNull($this->resolve(RateResult::carrierFailed(null), self::FLAT, 'WEB_MX'));
    }

    public function testANegativeFlatAmountIsNeverCharged(): void
    {
        self::assertNull($this->resolve(RateResult::carrierFailed(null), ['service' => '03', 'failure_policy' => 'flat', 'flat_amount' => ['WEB_US' => -100]]));
    }

    /**
     * An order without an address, a channel without an origin, units that cannot be packed, a service the carrier
     * does not offer or a rate without an exchange rate: the carrier did not fail, so no flat amount stands in.
     */
    public function testAShipmentThatCannotBeRatedIsUnavailableEvenWithAFlatAmount(): void
    {
        self::assertNull($this->resolve(RateResult::unavailable(), self::FLAT));
    }

    public function testAMethodWithoutAServiceIsUnavailableWithoutAskingForRates(): void
    {
        $rateProvider = $this->createMock(RateProviderInterface::class);
        $rateProvider->expects(self::never())->method('rateFor');

        self::assertNull((new ShippingChargeResolver($rateProvider))->resolve($this->shipment('WEB_US'), 'ups', ['failure_policy' => 'flat']));
    }

    public function testTheServiceOfTheMethodIsRatedWithItsCarrier(): void
    {
        $shipment = $this->shipment('WEB_US');
        $rateProvider = $this->createMock(RateProviderInterface::class);
        $rateProvider->expects(self::once())
            ->method('rateFor')
            ->with($shipment, 'fedex', 'FEDEX_GROUND')
            ->willReturn(RateResult::unavailable())
        ;

        (new ShippingChargeResolver($rateProvider))->resolve($shipment, 'fedex', ['service' => 'FEDEX_GROUND']);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function resolve(RateResult $result, array $configuration, string $channelCode = 'WEB_US'): ?ShippingCharge
    {
        $rateProvider = $this->createStub(RateProviderInterface::class);
        $rateProvider->method('rateFor')->willReturn($result);

        return (new ShippingChargeResolver($rateProvider))->resolve($this->shipment($channelCode), 'ups', $configuration);
    }

    private function shipment(string $channelCode): Shipment
    {
        $channel = new Channel();
        $channel->setCode($channelCode);

        $order = new Order();
        $order->setChannel($channel);

        $shipment = new Shipment();
        $order->addShipment($shipment);

        return $shipment;
    }
}
