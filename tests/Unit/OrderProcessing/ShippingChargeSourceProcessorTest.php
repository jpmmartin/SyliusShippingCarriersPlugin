<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\OrderProcessing;

use JpmMartin\SyliusShippingCarriersPlugin\OrderProcessing\ShippingChargeSourceProcessor;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Registry\ServiceRegistry;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Calculator\FlatRateCalculator;

final class ShippingChargeSourceProcessorTest extends TestCase
{
    #[DataProvider('sources')]
    public function testTheShippingAdjustmentRecordsWhereItsAmountComesFrom(RateResult $result, string $source): void
    {
        [$order, $adjustment] = $this->order('ups_rate', ['service' => '03', 'failure_policy' => 'flat', 'flat_amount' => ['WEB' => 1200]]);

        $this->processor($result)->process($order);

        self::assertSame($source, $adjustment->getDetails()['carrierRateSource'] ?? null);
        // What Sylius put there is kept.
        self::assertSame('UPS_GROUND', $adjustment->getDetails()['shippingMethodCode'] ?? null);
    }

    /**
     * @return iterable<string, array{RateResult, string}>
     */
    public static function sources(): iterable
    {
        yield 'a fresh rate' => [RateResult::quoted(new Rate('03', 1540, 'USD')), 'rate'];
        yield 'the last known rate' => [RateResult::carrierFailed(new Rate('03', 1490, 'USD')), 'last_known'];
        yield 'the flat amount' => [RateResult::carrierFailed(null), 'flat'];
    }

    public function testAShippingMethodOfAnotherCalculatorIsLeftAlone(): void
    {
        [$order, $adjustment] = $this->order('flat_rate', ['WEB' => ['amount' => 500]]);

        $this->processor(RateResult::quoted(new Rate('03', 1540, 'USD')))->process($order);

        self::assertArrayNotHasKey('carrierRateSource', $adjustment->getDetails());
    }

    public function testAnOrderThatIsNoLongerACartIsLeftAlone(): void
    {
        [$order, $adjustment] = $this->order('ups_rate', ['service' => '03']);
        $order->setState(OrderInterface::STATE_NEW);

        $this->processor(RateResult::quoted(new Rate('03', 1540, 'USD')))->process($order);

        self::assertArrayNotHasKey('carrierRateSource', $adjustment->getDetails());
    }

    private function processor(RateResult $result): ShippingChargeSourceProcessor
    {
        $rateProvider = $this->createStub(RateProviderInterface::class);
        $rateProvider->method('rateFor')->willReturn($result);
        $chargeResolver = new ShippingChargeResolver($rateProvider);

        $calculators = new ServiceRegistry(CalculatorInterface::class);
        $calculators->register('ups_rate', new CarrierRateCalculator($chargeResolver, 'ups', 'ups_rate'));
        $calculators->register('flat_rate', new FlatRateCalculator());

        return new ShippingChargeSourceProcessor($calculators, $chargeResolver);
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return array{Order, Adjustment}
     */
    private function order(string $calculator, array $configuration): array
    {
        $channel = new Channel();
        $channel->setCode('WEB');

        $method = new ShippingMethod();
        $method->setCode('UPS_GROUND');
        $method->setCalculator($calculator);
        $method->setConfiguration($configuration);

        $adjustment = new Adjustment();
        $adjustment->setType(AdjustmentInterface::SHIPPING_ADJUSTMENT);
        $adjustment->setAmount(1540);
        $adjustment->setDetails(['shippingMethodCode' => 'UPS_GROUND']);

        $order = new Order();
        $order->setChannel($channel);
        $shipment = new Shipment();
        $shipment->setMethod($method);
        $order->addShipment($shipment);
        $shipment->addAdjustment($adjustment);

        return [$order, $adjustment];
    }
}
