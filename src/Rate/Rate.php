<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Rate;

/**
 * The price of one carrier service for a shipment.
 */
final readonly class Rate
{
    /**
     * @param string $serviceCode The carrier's own code for the service
     * @param int $amount In the minor unit of the currency, as Sylius keeps amounts
     */
    public function __construct(
        public string $serviceCode,
        public int $amount,
        public string $currencyCode,
    ) {
    }
}
