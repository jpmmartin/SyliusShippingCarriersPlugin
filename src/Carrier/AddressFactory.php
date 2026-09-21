<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Component\Addressing\Model\AddressInterface;

/**
 * Builds the addresses the carriers read, from the shipping origin of a channel and from the address of an order.
 *
 * One place, because what the carriers accept is not what Sylius stores: the subdivision goes without its country
 * in front, and an address missing any of the four parts a carrier requires is no address at all.
 */
final readonly class AddressFactory
{
    /**
     * Null when the origin is incomplete: there is nothing to quote or to print from.
     */
    public function forOrigin(CarrierShippingOriginInterface $origin): ?Address
    {
        $countryCode = self::filled($origin->getCountryCode());
        $postcode = self::filled($origin->getPostcode());
        $city = self::filled($origin->getCity());
        $street = self::filled($origin->getStreet());
        if (null === $countryCode || null === $postcode || null === $city || null === $street) {
            return null;
        }

        return new Address(
            $countryCode,
            $postcode,
            $city,
            $street,
            self::subdivision($origin->getProvinceCode(), $countryCode),
            false,
            self::filled($origin->getCompanyName()),
            self::filled($origin->getContactName()),
            self::filled($origin->getPhone()),
        );
    }

    /**
     * Null when the order's address is incomplete.
     *
     * @param bool $residential Whether the destination is a home rather than a business, which changes the price
     */
    public function forDestination(?AddressInterface $address, bool $residential): ?Address
    {
        $countryCode = self::filled($address?->getCountryCode());
        $postcode = self::filled($address?->getPostcode());
        $city = self::filled($address?->getCity());
        $street = self::filled($address?->getStreet());
        if (null === $countryCode || null === $postcode || null === $city || null === $street) {
            return null;
        }

        return new Address(
            $countryCode,
            $postcode,
            $city,
            $street,
            self::subdivision($address?->getProvinceCode(), $countryCode),
            $residential,
            self::filled($address?->getCompany()),
            self::filled($address?->getFullName()),
            self::filled($address?->getPhoneNumber()),
        );
    }

    /**
     * Sylius codes a province with its country in front, `US-FL`, and carriers take the subdivision alone. The
     * origin's province is typed by hand, so it may come either way.
     */
    private static function subdivision(?string $provinceCode, string $countryCode): ?string
    {
        $provinceCode = self::filled($provinceCode);
        if (null === $provinceCode) {
            return null;
        }

        $prefix = strtoupper($countryCode) . '-';
        if (str_starts_with(strtoupper($provinceCode), $prefix)) {
            $provinceCode = self::filled(substr($provinceCode, strlen($prefix)));
        }

        return $provinceCode;
    }

    private static function filled(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }
}
