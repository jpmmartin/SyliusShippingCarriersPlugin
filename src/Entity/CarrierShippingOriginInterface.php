<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\Common\Collections\Collection;
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

    /**
     * The destination type rates are asked for with when the buyer has not chosen one (CA-48), a
     * DestinationType value.
     */
    public function getDefaultDestinationType(): ?string;

    public function setDefaultDestinationType(?string $defaultDestinationType): void;

    public function getMaxPackageWeight(): float;

    public function setMaxPackageWeight(float $maxPackageWeight): void;

    /**
     * The boxes this origin is restricted to. Empty means the whole catalog (CA-35).
     *
     * @return Collection<int, CarrierPackageBoxInterface>
     */
    public function getBoxes(): Collection;

    public function hasBox(CarrierPackageBoxInterface $box): bool;

    public function addBox(CarrierPackageBoxInterface $box): void;

    public function removeBox(CarrierPackageBoxInterface $box): void;
}
