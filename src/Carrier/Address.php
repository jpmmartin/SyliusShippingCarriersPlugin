<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

/**
 * An address as the carriers read it to rate a shipment. Only the province is optional: UPS's rating
 * schema requires the street, the city and the country, and Sylius requires those and the postcode in every
 * address, as the plugin does in an origin.
 */
final readonly class Address
{
    /**
     * @param string|null $provinceCode The subdivision as the carriers take it, without a country prefix: `CA`
     * @param bool $residential Whether a destination is a home rather than a business, which changes the price
     *                          (CA-48); meaningless for an origin
     */
    public function __construct(
        public string $countryCode,
        public string $postcode,
        public string $city,
        public string $street,
        public ?string $provinceCode = null,
        public bool $residential = false,
    ) {
    }
}
