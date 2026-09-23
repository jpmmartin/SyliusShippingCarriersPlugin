<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Shipping\Eligibility;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Component\Shipping\Checker\Eligibility\ShippingMethodEligibilityCheckerInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Sylius\Component\Shipping\Model\ShippingSubjectInterface;

/**
 * A carrier's shipping method is eligible only while it has something to charge: a rate, the last known rate or
 * the flat amount of its policy.
 *
 * Sylius asks every eligibility checker before it offers a shipping method, before it assigns one by default and
 * before it completes an order, in the shop and in the API. So an unavailable shipping method is neither offered
 * nor completed with, and a shipment is never left without a charge.
 *
 * @internal
 */
final readonly class CarrierRateEligibilityChecker implements ShippingMethodEligibilityCheckerInterface
{
    public function __construct(
        private ServiceRegistryInterface $calculators,
        private ShippingChargeResolver $chargeResolver,
    ) {
    }

    public function isEligible(ShippingSubjectInterface $shippingSubject, ShippingMethodInterface $shippingMethod): bool
    {
        $calculatorName = $shippingMethod->getCalculator();
        $calculator = null !== $calculatorName && $this->calculators->has($calculatorName) ? $this->calculators->get($calculatorName) : null;

        // Shipping methods of other calculators are not this checker's business.
        if (!$calculator instanceof CarrierRateCalculator) {
            return true;
        }

        if (!$shippingSubject instanceof ShipmentInterface) {
            return false;
        }

        return null !== $this->chargeResolver->resolve($shippingSubject, $calculator->getCarrier(), $shippingMethod->getConfiguration());
    }
}
