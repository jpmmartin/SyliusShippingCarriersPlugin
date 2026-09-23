<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Shipping;

use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * Decides what a shipping method of a carrier charges, in this order: the carrier's rate; when the carrier fails,
 * the last rate it gave for the same shipment; without one, the flat amount of the channel if that is the method's
 * policy.
 *
 * Whether a shipping method is offered and what it charges both come from here, so the price shown is the price
 * charged.
 *
 * @internal
 */
final readonly class ShippingChargeResolver
{
    public function __construct(
        private RateProviderInterface $rateProvider,
    ) {
    }

    /**
     * Null when the shipping method is unavailable for the shipment: nothing to charge, so it must not be offered.
     *
     * @param string $carrier The carrier's code, such as `ups`
     * @param array<array-key, mixed> $configuration The configuration of the shipping method
     */
    public function resolve(ShipmentInterface $shipment, string $carrier, array $configuration): ?ShippingCharge
    {
        $service = $configuration[CarrierRateCalculator::SERVICE] ?? null;
        if (!is_string($service)) {
            return null;
        }

        $result = $this->rateProvider->rateFor($shipment, $carrier, $service);
        if (null !== $result->rate) {
            return new ShippingCharge($result->rate->amount, ShippingCharge::SOURCE_RATE);
        }

        // A service the carrier does not offer, or a shipment that cannot be rated, has no fallback.
        if (!$result->carrierFailed) {
            return null;
        }

        if (null !== $result->lastKnownRate) {
            return new ShippingCharge($result->lastKnownRate->amount, ShippingCharge::SOURCE_LAST_KNOWN);
        }

        if (FailurePolicy::FLAT !== ($configuration[CarrierRateCalculator::FAILURE_POLICY] ?? FailurePolicy::HIDE)) {
            return null;
        }

        $channelCode = $shipment->getOrder()?->getChannel()?->getCode();
        $flatAmounts = $configuration[CarrierRateCalculator::FLAT_AMOUNT] ?? null;
        $amount = null !== $channelCode && is_array($flatAmounts) ? ($flatAmounts[$channelCode] ?? null) : null;

        return is_int($amount) && $amount >= 0 ? new ShippingCharge($amount, ShippingCharge::SOURCE_FLAT) : null;
    }
}
