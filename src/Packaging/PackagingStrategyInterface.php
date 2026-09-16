<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Packaging;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException;
use Sylius\Component\Shipping\Model\ShipmentInterface;

/**
 * Groups the units of a shipment into the packages that are quoted (CA-14). The plugin depends only on
 * this interface, so another strategy can replace the default one (CA-16).
 *
 * An implementation must be deterministic: the packages are computed again when the order is completed,
 * and they are stored only because they match the quoted ones (D-9).
 */
interface PackagingStrategyInterface
{
    /**
     * @return non-empty-list<Package> Measured in the origin's units (D-16)
     *
     * @throws UnpackableShipmentException When the shipment cannot be quoted: a variant has no weight (CA-17),
     *                                     or a unit fits no box, not even on its own (CA-40)
     */
    public function pack(ShipmentInterface $shipment, CarrierShippingOriginInterface $origin): array;
}
