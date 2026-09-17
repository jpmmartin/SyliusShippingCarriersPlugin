<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Calculator\UndefinedShippingMethodException;
use Sylius\Component\Shipping\Model\ShipmentInterface as BaseShipmentInterface;

/**
 * Charges what a carrier's shipping method costs for a shipment: the carrier's rate or, when the carrier fails, the
 * last known rate or the flat amount of the channel. One instance per carrier.
 *
 * The configuration of a shipping method, set in the admin's shipping methods, holds the service, what to do
 * when the carrier fails, and a flat amount by channel code.
 */
final readonly class CarrierRateCalculator implements CalculatorInterface
{
    public const SERVICE = 'service';

    public const FAILURE_POLICY = 'failure_policy';

    public const FLAT_AMOUNT = 'flat_amount';

    /**
     * @param string $carrier The carrier's code, such as `ups`
     * @param string $type The calculator's name in Sylius, such as `ups_rate`
     */
    public function __construct(
        private ShippingChargeResolver $chargeResolver,
        private string $carrier,
        private string $type,
    ) {
    }

    /**
     * Sylius catches the exception and leaves the shipment without a charge, so it only reaches a shipping method that
     * is not eligible: one Sylius neither offers nor lets an order complete with.
     *
     * @throws UndefinedShippingMethodException When the shipping method is unavailable for the shipment
     */
    public function calculate(BaseShipmentInterface $subject, array $configuration): int
    {
        if (!$subject instanceof ShipmentInterface) {
            throw new \InvalidArgumentException(sprintf('Only a shipment of an order can be rated, not a %s.', get_debug_type($subject)));
        }

        $charge = $this->chargeResolver->resolve($subject, $this->carrier, $configuration);
        if (null === $charge) {
            throw new UndefinedShippingMethodException(sprintf('The %s shipping method is unavailable for this shipment.', $this->carrier));
        }

        return $charge->amount;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * The carrier whose rates this calculator charges, such as `ups`.
     */
    public function getCarrier(): string
    {
        return $this->carrier;
    }
}
