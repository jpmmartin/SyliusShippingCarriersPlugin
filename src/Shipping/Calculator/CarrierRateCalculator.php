<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator;

use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Calculator\UndefinedShippingMethodException;
use Sylius\Component\Shipping\Model\ShipmentInterface as BaseShipmentInterface;

/**
 * Charges the rate a carrier gives for the service a shipping method represents. One instance per carrier.
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
        private RateProviderInterface $rateProvider,
        private string $carrier,
        private string $type,
    ) {
    }

    /**
     * @throws UndefinedShippingMethodException When there is no rate to charge
     */
    public function calculate(BaseShipmentInterface $subject, array $configuration): int
    {
        if (!$subject instanceof ShipmentInterface) {
            throw new \InvalidArgumentException(sprintf('Only a shipment of an order can be rated, not a %s.', get_debug_type($subject)));
        }

        $service = $configuration[self::SERVICE] ?? null;
        if (!is_string($service)) {
            throw new UndefinedShippingMethodException(sprintf('The shipping method has no %s service.', $this->carrier));
        }

        $rate = $this->rateProvider->rateFor($subject, $this->carrier, $service)->rate;
        if (null === $rate) {
            throw new UndefinedShippingMethodException(sprintf('There is no %s rate for the service "%s".', $this->carrier, $service));
        }

        return $rate->amount;
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
