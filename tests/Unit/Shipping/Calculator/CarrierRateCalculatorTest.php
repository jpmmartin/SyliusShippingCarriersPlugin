<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Shipping\Calculator;

use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Registry\ServiceRegistry;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Calculator\DelegatingCalculator;
use Sylius\Component\Shipping\Calculator\UndefinedShippingMethodException;

final class CarrierRateCalculatorTest extends TestCase
{
    public function testItChargesWhatTheChargeResolverDecides(): void
    {
        $amount = $this->calculator(RateResult::carrierFailed(new Rate('03', 1490, 'USD')))
            ->calculate(new Shipment(), ['service' => '03', 'failure_policy' => 'hide'])
        ;

        self::assertSame(1490, $amount);
    }

    public function testItIsNamedAfterItsCarrier(): void
    {
        $calculator = $this->calculator(RateResult::unavailable());

        self::assertSame('ups_rate', $calculator->getType());
        self::assertSame('ups', $calculator->getCarrier());
    }

    /**
     * Sylius leaves the shipment without a charge when a calculator throws this exception.
     */
    public function testAnUnavailableShippingMethodIsUndefined(): void
    {
        $this->expectException(UndefinedShippingMethodException::class);

        $this->calculator(RateResult::carrierFailed(null))->calculate(new Shipment(), ['service' => '03', 'failure_policy' => 'hide']);
    }

    /**
     * Sylius refuses a shipment without a shipping method before any calculator is asked.
     */
    public function testAShipmentWithoutAShippingMethodNeverReachesTheCalculator(): void
    {
        $rateProvider = $this->createMock(RateProviderInterface::class);
        $rateProvider->expects(self::never())->method('rateFor');

        $calculators = new ServiceRegistry(CalculatorInterface::class);
        $calculators->register('ups_rate', new CarrierRateCalculator(new ShippingChargeResolver($rateProvider), 'ups', 'ups_rate'));

        $this->expectException(UndefinedShippingMethodException::class);

        (new DelegatingCalculator($calculators))->calculate(new Shipment());
    }

    public function testAShipmentWithAShippingMethodIsDelegatedToIt(): void
    {
        $calculators = new ServiceRegistry(CalculatorInterface::class);
        $calculators->register('ups_rate', $this->calculator(RateResult::quoted(new Rate('03', 1540, 'USD'))));

        $method = new ShippingMethod();
        $method->setCalculator('ups_rate');
        $method->setConfiguration(['service' => '03']);
        $shipment = new Shipment();
        $shipment->setMethod($method);

        self::assertSame(1540, (new DelegatingCalculator($calculators))->calculate($shipment));
    }

    private function calculator(RateResult $result): CarrierRateCalculator
    {
        $rateProvider = $this->createStub(RateProviderInterface::class);
        $rateProvider->method('rateFor')->willReturn($result);

        return new CarrierRateCalculator(new ShippingChargeResolver($rateProvider), 'ups', 'ups_rate');
    }
}
