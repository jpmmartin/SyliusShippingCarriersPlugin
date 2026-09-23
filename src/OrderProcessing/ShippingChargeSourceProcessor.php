<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\OrderProcessing;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;

/**
 * Records in the shipping adjustment of a carrier's shipping method where its amount comes from: a fresh rate, the
 * last known rate or the flat amount. So an order tells a real rate from a fallback.
 *
 * Runs after Sylius's shipping charges processor, which creates the adjustment without asking the calculator for
 * anything but the amount.
 *
 * @internal
 */
final readonly class ShippingChargeSourceProcessor implements OrderProcessorInterface
{
    public const DETAIL = 'carrierRateSource';

    public function __construct(
        private ServiceRegistryInterface $calculators,
        private ShippingChargeResolver $chargeResolver,
    ) {
    }

    public function process(BaseOrderInterface $order): void
    {
        if (!$order instanceof OrderInterface || !$order->canBeProcessed()) {
            return;
        }

        foreach ($order->getShipments() as $shipment) {
            $calculatorName = $shipment->getMethod()?->getCalculator();
            $calculator = null !== $calculatorName && $this->calculators->has($calculatorName) ? $this->calculators->get($calculatorName) : null;
            if (!$calculator instanceof CarrierRateCalculator) {
                continue;
            }

            $charge = $this->chargeResolver->resolve($shipment, $calculator->getCarrier(), $shipment->getMethod()?->getConfiguration() ?? []);
            if (null === $charge) {
                continue;
            }

            foreach ($shipment->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT) as $adjustment) {
                $adjustment->setDetails([self::DETAIL => $charge->source] + $adjustment->getDetails());
            }
        }
    }
}
