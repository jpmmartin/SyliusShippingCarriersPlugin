<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Twig;

use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShipmentCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingEvent;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Twig\ShipmentTrackingRuntime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Registry\ServiceRegistry;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Calculator\FlatRateCalculator;

final class ShipmentTrackingRuntimeTest extends TestCase
{
    private const TRACKING_NUMBER = '1Z999AA10123456784';

    /** @var TrackingProviderInterface&MockObject */
    private TrackingProviderInterface $trackingProvider;

    protected function setUp(): void
    {
        $this->trackingProvider = $this->createMock(TrackingProviderInterface::class);
    }

    public function testAShipmentWithATrackingNumberIsToldWithWhatTheCarrierSaid(): void
    {
        $this->trackingProvider->expects(self::once())->method('track')->willReturn(
            new TrackingInfo(self::TRACKING_NUMBER, 'Delivered', [
                new TrackingEvent(new \DateTimeImmutable('2026-09-17 10:15:00'), 'Delivered', 'Seattle, WA, US'),
            ]),
        );

        $tracked = $this->runtime()->ofOrder($this->order($this->shipment()));

        self::assertCount(1, $tracked);
        self::assertSame(self::TRACKING_NUMBER, $tracked[0]->trackingNumber);
        self::assertSame('Delivered', $tracked[0]->tracking?->status);
    }

    /**
     * The buyer is owed the number even when nobody could say where the shipment is.
     */
    public function testAShipmentWhoseCarrierCouldNotBeAskedKeepsItsNumber(): void
    {
        $this->trackingProvider->expects(self::once())->method('track')->willReturn(null);

        $tracked = $this->runtime()->ofOrder($this->order($this->shipment()));

        self::assertCount(1, $tracked);
        self::assertSame(self::TRACKING_NUMBER, $tracked[0]->trackingNumber);
        self::assertNull($tracked[0]->tracking);
    }

    public function testAShipmentWithoutATrackingNumberIsLeftOutAndNobodyIsAsked(): void
    {
        $this->trackingProvider->expects(self::never())->method('track');

        $shipment = $this->shipment();
        $shipment->setTracking(null);

        self::assertSame([], $this->runtime()->ofOrder($this->order($shipment)));
    }

    public function testATrackingNumberOfOnlySpacesIsLeftOutAndNobodyIsAsked(): void
    {
        $this->trackingProvider->expects(self::never())->method('track');

        $shipment = $this->shipment();
        $shipment->setTracking('   ');

        self::assertSame([], $this->runtime()->ofOrder($this->order($shipment)));
    }

    /**
     * A shipping method that is not quoted by a carrier is none of the plugin's business, even if somebody typed a
     * tracking number into it.
     */
    public function testAShipmentOfAnotherShippingMethodIsLeftOutAndNobodyIsAsked(): void
    {
        $this->trackingProvider->expects(self::never())->method('track');

        self::assertSame([], $this->runtime()->ofOrder($this->order($this->shipment('flat_rate'))));
    }

    public function testEveryShipmentOfTheOrderIsTold(): void
    {
        $this->trackingProvider->expects(self::exactly(2))->method('track')->willReturn(null);

        $second = $this->shipment();
        $second->setTracking('1Z999AA10123456785');

        $tracked = $this->runtime()->ofOrder($this->order($this->shipment(), $second, $this->shipment('flat_rate')));

        self::assertSame(
            [self::TRACKING_NUMBER, '1Z999AA10123456785'],
            array_map(static fn ($shipment): string => $shipment->trackingNumber, $tracked),
        );
    }

    private function runtime(): ShipmentTrackingRuntime
    {
        $calculators = new ServiceRegistry(CalculatorInterface::class);
        $chargeResolver = new ShippingChargeResolver($this->createStub(RateProviderInterface::class));
        $calculators->register('ups_rate', new CarrierRateCalculator($chargeResolver, 'ups', 'ups_rate'));
        $calculators->register('flat_rate', new FlatRateCalculator());

        return new ShipmentTrackingRuntime(new ShipmentCarrier($calculators), $this->trackingProvider);
    }

    private function order(Shipment ...$shipments): Order
    {
        $order = new Order();
        foreach ($shipments as $shipment) {
            $order->addShipment($shipment);
        }

        return $order;
    }

    private function shipment(string $calculator = 'ups_rate'): Shipment
    {
        $method = new ShippingMethod();
        $method->setCalculator($calculator);

        $shipment = new Shipment();
        $shipment->setMethod($method);
        $shipment->setTracking(self::TRACKING_NUMBER);

        return $shipment;
    }
}
