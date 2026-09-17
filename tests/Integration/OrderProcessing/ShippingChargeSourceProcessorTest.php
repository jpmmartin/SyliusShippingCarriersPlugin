<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\OrderProcessing;

use JpmMartin\SyliusShippingCarriersPlugin\OrderProcessing\ShippingChargeSourceProcessor;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use Laminas\Stdlib\PriorityQueue;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\OrderProcessing\ShippingChargesProcessor;
use Sylius\Component\Order\Processor\CompositeOrderProcessor;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShippingChargeSourceProcessorTest extends KernelTestCase
{
    /**
     * Sylius creates the shipping adjustment; the source can only be recorded on it afterwards.
     */
    public function testItRunsAfterSyliusCreatesTheShippingAdjustment(): void
    {
        self::bootKernel();

        $processor = self::getContainer()->get('sylius.order_processing.order_processor');
        self::assertInstanceOf(CompositeOrderProcessor::class, $processor);

        $queue = (new \ReflectionProperty(CompositeOrderProcessor::class, 'orderProcessors'))->getValue($processor);
        self::assertInstanceOf(PriorityQueue::class, $queue);
        // Iterating gives the processors in the order they run; toArray() gives them in the order they were added.
        $classes = array_map(static fn (mixed $processor): string => get_debug_type($processor), iterator_to_array($queue, false));

        $shippingCharges = array_search(ShippingChargesProcessor::class, $classes, true);
        $source = array_search(ShippingChargeSourceProcessor::class, $classes, true);
        self::assertIsInt($shippingCharges);
        self::assertIsInt($source);
        self::assertGreaterThan($shippingCharges, $source);
    }

    /**
     * With the carrier down and no rate to fall back on, the flat amount is charged and the order says so.
     */
    public function testAFlatAmountChargedByAUpsMethodIsRecordedAsSuch(): void
    {
        self::bootKernel();

        $rateProvider = $this->createStub(RateProviderInterface::class);
        $rateProvider->method('rateFor')->willReturn(RateResult::carrierFailed(null));
        self::getContainer()->set('jpmmartin_carrier.rate_provider', $rateProvider);

        $channel = new Channel();
        $channel->setCode('WEB');
        $method = new ShippingMethod();
        $method->setCode('UPS_GROUND');
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('UPS Ground');
        $method->setCalculator('ups_rate');
        $method->setConfiguration(['service' => '03', 'failure_policy' => 'flat', 'flat_amount' => ['WEB' => 1200]]);
        $order = new Order();
        $order->setChannel($channel);
        $shipment = new Shipment();
        $shipment->setMethod($method);
        $order->addShipment($shipment);

        foreach (['sylius.order_processing.shipping_charges_processor', 'jpmmartin_carrier.order_processing.shipping_charge_source'] as $id) {
            $processor = self::getContainer()->get($id);
            self::assertInstanceOf(OrderProcessorInterface::class, $processor);
            $processor->process($order);
        }

        $adjustments = $shipment->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT);
        self::assertCount(1, $adjustments);
        $adjustment = $adjustments->first();
        self::assertInstanceOf(AdjustmentInterface::class, $adjustment);
        self::assertSame(1200, $adjustment->getAmount());
        self::assertSame('flat', $adjustment->getDetails()['carrierRateSource'] ?? null);
    }
}
