<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\Common\Collections\Collection;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * The packages of a shipment, stored when its order is completed (CA-42), or the reason they could not be
 * (CA-45). Nothing recomputes or changes them afterwards (CA-43, CA-44).
 */
interface CarrierShipmentPackagingInterface extends ResourceInterface
{
    public const STATE_PERSISTED = 'persisted';

    public const STATE_FAILED = 'failed';

    public function getShipment(): ?ShipmentInterface;

    public function setShipment(?ShipmentInterface $shipment): void;

    public function getState(): string;

    /** Only a failed packaging has one. */
    public function getFailureReason(): ?string;

    /** Records that the packages could not be stored, and why (CA-45). */
    public function fail(string $reason): void;

    public function getCreatedAt(): \DateTimeImmutable;

    /**
     * @return Collection<int, CarrierShipmentPackageInterface> In the order they were added
     */
    public function getPackages(): Collection;

    /** Adds the package after the ones already added. */
    public function addPackage(CarrierShipmentPackageInterface $package): void;
}
