<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Rate;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationTypeResolverInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\PackagingStrategyInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * Turns a shipment into what a carrier is asked to rate: from the origin of its channel to the shipping address
 * of its order, in the packages the packaging strategy builds.
 */
final readonly class RateRequestFactory
{
    /**
     * @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository
     */
    public function __construct(
        private RepositoryInterface $originRepository,
        private PackagingStrategyInterface $packagingStrategy,
        private DestinationTypeResolverInterface $destinationTypeResolver,
    ) {
    }

    /**
     * Null when the shipment cannot be rated: its order has no complete shipping address, its channel has no
     * complete origin, or its units cannot be packed, which the packaging strategy logs.
     */
    public function create(ShipmentInterface $shipment): ?RateRequest
    {
        $order = $shipment->getOrder();
        if (!$order instanceof OrderInterface) {
            return null;
        }

        // Checked first: without an address there is nothing to rate, so nothing is packed either.
        $shippingAddress = $order->getShippingAddress();
        $countryCode = self::filled($shippingAddress?->getCountryCode());
        $postcode = self::filled($shippingAddress?->getPostcode());
        $city = self::filled($shippingAddress?->getCity());
        $street = self::filled($shippingAddress?->getStreet());
        if (null === $countryCode || null === $postcode || null === $city || null === $street) {
            return null;
        }

        $channel = $order->getChannel();
        $origin = null === $channel ? null : $this->originRepository->findOneBy(['channel' => $channel]);
        if (!$origin instanceof CarrierShippingOriginInterface) {
            return null;
        }

        $originAddress = self::originAddress($origin);
        if (null === $originAddress) {
            return null;
        }

        try {
            $packages = $this->packagingStrategy->pack($shipment, $origin);
        } catch (UnpackableShipmentException) {
            return null;
        }

        $destination = new Address(
            $countryCode,
            $postcode,
            $city,
            $street,
            self::subdivision($shippingAddress?->getProvinceCode(), $countryCode),
            DestinationType::RESIDENTIAL === $this->destinationTypeResolver->resolve($order),
        );

        return new RateRequest($originAddress, $destination, $packages);
    }

    private static function originAddress(CarrierShippingOriginInterface $origin): ?Address
    {
        $countryCode = self::filled($origin->getCountryCode());
        $postcode = self::filled($origin->getPostcode());
        $city = self::filled($origin->getCity());
        $street = self::filled($origin->getStreet());
        if (null === $countryCode || null === $postcode || null === $city || null === $street) {
            return null;
        }

        return new Address($countryCode, $postcode, $city, $street, self::subdivision($origin->getProvinceCode(), $countryCode));
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
