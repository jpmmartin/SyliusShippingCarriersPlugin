<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Shipping\Eligibility;

use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Eligibility\CarrierRateEligibilityChecker;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Registry\ServiceRegistry;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Calculator\FlatRateCalculator;
use Sylius\Component\Shipping\Model\ShippingSubjectInterface;

final class CarrierRateEligibilityCheckerTest extends TestCase
{
    public function testAMethodWithARateIsEligible(): void
    {
        self::assertTrue($this->checker(RateResult::quoted(new Rate('03', 1540, 'USD')))->isEligible($this->shipment(), $this->method('ups_rate', 'hide')));
    }

    public function testAMethodChargingTheLastKnownRateIsEligible(): void
    {
        self::assertTrue($this->checker(RateResult::carrierFailed(new Rate('03', 1490, 'USD')))->isEligible($this->shipment(), $this->method('ups_rate', 'hide')));
    }

    public function testAMethodChargingItsFlatAmountIsEligible(): void
    {
        self::assertTrue($this->checker(RateResult::carrierFailed(null))->isEligible($this->shipment(), $this->method('ups_rate', 'flat')));
    }

    public function testAHiddenMethodWithoutARateIsNotEligible(): void
    {
        self::assertFalse($this->checker(RateResult::carrierFailed(null))->isEligible($this->shipment(), $this->method('ups_rate', 'hide')));
    }

    public function testAMethodThatCannotRateTheShipmentIsNotEligible(): void
    {
        self::assertFalse($this->checker(RateResult::unavailable())->isEligible($this->shipment(), $this->method('ups_rate', 'flat')));
    }

    /**
     * Whether another calculator's shipping method is eligible is for the other checkers to say.
     */
    public function testAMethodOfAnotherCalculatorIsNotItsBusiness(): void
    {
        $rateProvider = $this->createMock(RateProviderInterface::class);
        $rateProvider->expects(self::never())->method('rateFor');

        self::assertTrue($this->checkerWith($rateProvider)->isEligible($this->shipment(), $this->method('flat_rate', 'hide')));
    }

    public function testACarrierMethodForSomethingOtherThanAShipmentOfAnOrderIsNotEligible(): void
    {
        self::assertFalse($this->checker(RateResult::quoted(new Rate('03', 1540, 'USD')))->isEligible($this->createStub(ShippingSubjectInterface::class), $this->method('ups_rate', 'hide')));
    }

    private function checker(RateResult $result): CarrierRateEligibilityChecker
    {
        $rateProvider = $this->createStub(RateProviderInterface::class);
        $rateProvider->method('rateFor')->willReturn($result);

        return $this->checkerWith($rateProvider);
    }

    private function checkerWith(RateProviderInterface $rateProvider): CarrierRateEligibilityChecker
    {
        $chargeResolver = new ShippingChargeResolver($rateProvider);
        $calculators = new ServiceRegistry(CalculatorInterface::class);
        $calculators->register('ups_rate', new CarrierRateCalculator($chargeResolver, 'ups', 'ups_rate'));
        $calculators->register('flat_rate', new FlatRateCalculator());

        return new CarrierRateEligibilityChecker($calculators, $chargeResolver);
    }

    private function method(string $calculator, string $failurePolicy): ShippingMethod
    {
        $method = new ShippingMethod();
        $method->setCalculator($calculator);
        $method->setConfiguration(['service' => '03', 'failure_policy' => $failurePolicy, 'flat_amount' => ['WEB' => 1200]]);

        return $method;
    }

    private function shipment(): Shipment
    {
        $channel = new Channel();
        $channel->setCode('WEB');
        $order = new Order();
        $order->setChannel($channel);
        $shipment = new Shipment();
        $order->addShipment($shipment);

        return $shipment;
    }
}
