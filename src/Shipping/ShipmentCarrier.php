<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Shipping;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;

/**
 * Which carrier a shipment belongs to, read from the calculator of its shipping method so that nobody has to say it.
 *
 * @internal
 */
final readonly class ShipmentCarrier
{
    public function __construct(
        private ServiceRegistryInterface $calculators,
    ) {
    }

    /**
     * Null when the shipment has no method, or its method is not quoted by a carrier.
     */
    public function of(ShipmentInterface $shipment): ?string
    {
        $calculatorName = $shipment->getMethod()?->getCalculator();
        $calculator = null !== $calculatorName && $this->calculators->has($calculatorName) ? $this->calculators->get($calculatorName) : null;

        return $calculator instanceof CarrierRateCalculator ? $calculator->getCarrier() : null;
    }
}
