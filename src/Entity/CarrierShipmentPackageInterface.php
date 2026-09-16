<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\Common\Collections\Collection;
use Sylius\Component\Shipping\Model\ShipmentUnitInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * A stored package. Its values are copied, not read from the box or the variants, so later changes to either
 * leave it as it was (D-10).
 */
interface CarrierShipmentPackageInterface extends ResourceInterface
{
    public function getPackaging(): ?CarrierShipmentPackagingInterface;

    public function setPackaging(?CarrierShipmentPackagingInterface $packaging): void;

    public function getPosition(): int;

    public function setPosition(int $position): void;

    /** Null for the fallback package, which uses no box (CA-39). */
    public function getBoxName(): ?string;

    public function setBoxName(?string $boxName): void;

    /** The outer measures declared to the carrier (CA-36), in the dimension unit. */
    public function getLength(): ?float;

    public function setLength(?float $length): void;

    public function getWidth(): ?float;

    public function setWidth(?float $width): void;

    public function getHeight(): ?float;

    public function setHeight(?float $height): void;

    public function getDimensionUnit(): ?string;

    public function setDimensionUnit(?string $dimensionUnit): void;

    /** The contents plus the empty box (CA-37), in the weight unit. */
    public function getWeight(): ?float;

    public function setWeight(?float $weight): void;

    public function getWeightUnit(): ?string;

    public function setWeightUnit(?string $weightUnit): void;

    /**
     * @return Collection<int, ShipmentUnitInterface> The order item units it carries
     */
    public function getUnits(): Collection;

    public function addUnit(ShipmentUnitInterface $unit): void;
}
