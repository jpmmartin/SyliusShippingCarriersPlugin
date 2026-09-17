<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Rate;

use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * The only way to a carrier's rates. A single call to the carrier rates every one of its services for a
 * shipment, and they are all kept: asking for another service of the same shipment does not call it again.
 */
interface RateProviderInterface
{
    /**
     * @param string $carrier The code of the carrier, such as `ups`
     * @param string $serviceCode The carrier's own code for the service
     */
    public function rateFor(ShipmentInterface $shipment, string $carrier, string $serviceCode): RateResult;
}
