<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

/**
 * An address as the carriers read it to rate a shipment. FedEx rates without the street, and UPS takes it
 * as optional lines, so only the country is required here.
 */
final readonly class Address
{
    public function __construct(
        public string $countryCode,
        public ?string $postcode = null,
        public ?string $provinceCode = null,
        public ?string $city = null,
        public ?string $street = null,
    ) {
    }
}
