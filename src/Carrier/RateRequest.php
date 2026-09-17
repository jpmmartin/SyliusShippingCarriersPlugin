<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;

/**
 * What a carrier is asked to rate: the packages of a shipment between two addresses. No service is named,
 * so a single call returns every service.
 */
final readonly class RateRequest
{
    /**
     * @param non-empty-list<Package> $packages Each one with the outer measures and the weight declared to the
     *                                          carrier, in its own units
     */
    public function __construct(
        public Address $origin,
        public Address $destination,
        public array $packages,
    ) {
    }
}
