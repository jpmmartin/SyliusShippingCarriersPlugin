<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Resource\Model\ResourceInterface;

interface CarrierShippingOriginInterface extends ResourceInterface
{
    public const WEIGHT_UNIT_KG = 'kg';

    public const WEIGHT_UNIT_LB = 'lb';

    public const DIMENSION_UNIT_CM = 'cm';

    public const DIMENSION_UNIT_IN = 'in';

    /**
     * Lowest maximum package weight published by the supported carriers.
     *
     * UPS allows 70 kg but FedEx Express tops out at 68 kg including packaging,
     * so 70 would build packages one carrier accepts and the other rejects.
     */
    public const DEFAULT_MAX_PACKAGE_WEIGHT_KG = 68.0;

    public const DEFAULT_MAX_PACKAGE_WEIGHT_LB = 150.0;

    public function getChannel(): ?ChannelInterface;

    public function setChannel(?ChannelInterface $channel): void;

    public function getStreet(): ?string;

    public function setStreet(?string $street): void;

    public function getCity(): ?string;

    public function setCity(?string $city): void;

    public function getPostcode(): ?string;

    public function setPostcode(?string $postcode): void;

    public function getCountryCode(): ?string;

    public function setCountryCode(?string $countryCode): void;

    public function getProvinceCode(): ?string;

    public function setProvinceCode(?string $provinceCode): void;

    public function getWeightUnit(): string;

    public function setWeightUnit(string $weightUnit): void;

    public function getDimensionUnit(): string;

    public function setDimensionUnit(string $dimensionUnit): void;

    public function getMaxPackageWeight(): float;

    public function setMaxPackageWeight(float $maxPackageWeight): void;
}
