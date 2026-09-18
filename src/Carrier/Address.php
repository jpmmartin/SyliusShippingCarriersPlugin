<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

/**
 * An address as the carriers read it. Only the province is optional: UPS's rating schema requires the street,
 * the city and the country, and Sylius requires those and the postcode in every address, as the plugin does
 * in an origin.
 *
 * Who is at the address is optional here because rating never asks for it. Issuing a label does: a carrier
 * refuses to print one without a name and a phone to return the parcel to.
 */
final readonly class Address
{
    /**
     * @param string|null $provinceCode The subdivision as the carriers take it, without a country prefix: `CA`
     * @param bool $residential Whether a destination is a home rather than a business, which changes the price;
     *                          meaningless for an origin
     */
    public function __construct(
        public string $countryCode,
        public string $postcode,
        public string $city,
        public string $street,
        public ?string $provinceCode = null,
        public bool $residential = false,
        public ?string $companyName = null,
        public ?string $contactName = null,
        public ?string $phone = null,
    ) {
    }

    /**
     * Whether a label can be printed for this address.
     */
    public function hasContact(): bool
    {
        return null !== $this->contactName && '' !== $this->contactName &&
            null !== $this->phone && '' !== $this->phone;
    }

    /**
     * What the carrier prints as the name. A company when there is one, the person otherwise.
     */
    public function name(): ?string
    {
        return null !== $this->companyName && '' !== $this->companyName ? $this->companyName : $this->contactName;
    }
}
