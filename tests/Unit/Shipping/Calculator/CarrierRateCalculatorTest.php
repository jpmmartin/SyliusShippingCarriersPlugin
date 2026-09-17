<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Shipping\Calculator;

use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Shipping\Calculator\UndefinedShippingMethodException;

final class CarrierRateCalculatorTest extends TestCase
{
    public function testItChargesTheRateOfTheServiceOfTheShippingMethod(): void
    {
        $shipment = new Shipment();
        $rateProvider = $this->createMock(RateProviderInterface::class);
        $rateProvider->expects(self::once())
            ->method('rateFor')
            ->with($shipment, 'ups', '03')
            ->willReturn(RateResult::quoted(new Rate('03', 1540, 'USD')))
        ;

        $amount = (new CarrierRateCalculator($rateProvider, 'ups', 'ups_rate'))->calculate($shipment, ['service' => '03']);

        self::assertSame(1540, $amount);
    }

    public function testItIsNamedAfterItsCarrier(): void
    {
        $calculator = new CarrierRateCalculator($this->createStub(RateProviderInterface::class), 'ups', 'ups_rate');

        self::assertSame('ups_rate', $calculator->getType());
        self::assertSame('ups', $calculator->getCarrier());
    }

    /**
     * Sylius leaves the shipment without a charge when a calculator throws this exception.
     */
    public function testWithoutARateTheShippingMethodIsUndefined(): void
    {
        $rateProvider = $this->createStub(RateProviderInterface::class);
        $rateProvider->method('rateFor')->willReturn(RateResult::unavailable());

        $this->expectException(UndefinedShippingMethodException::class);

        (new CarrierRateCalculator($rateProvider, 'ups', 'ups_rate'))->calculate(new Shipment(), ['service' => '03']);
    }

    public function testAShippingMethodWithoutAServiceIsUndefined(): void
    {
        $rateProvider = $this->createMock(RateProviderInterface::class);
        $rateProvider->expects(self::never())->method('rateFor');

        $this->expectException(UndefinedShippingMethodException::class);

        (new CarrierRateCalculator($rateProvider, 'ups', 'ups_rate'))->calculate(new Shipment(), []);
    }
}
