<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;

/**
 * Everything a carrier needs to issue the labels of one shipment.
 */
final readonly class ShipmentRequest
{
    /**
     * @param string $serviceCode The carrier's own code for the service the buyer was charged for
     * @param non-empty-list<ShipmentPackage> $packages One entry per package, in the order they were packed
     * @param string $labelFormat What to ask the carrier to print, for instance PDF or ZPL
     */
    public function __construct(
        public Address $origin,
        public Address $destination,
        public string $serviceCode,
        public array $packages,
        public string $labelFormat,
    ) {
    }

    /**
     * Whether customs has to be told anything at all: a shipment that never leaves its country is not
     * declared (002/CA-6).
     */
    public function crossesABorder(): bool
    {
        return $this->origin->countryCode !== $this->destination->countryCode;
    }
}
