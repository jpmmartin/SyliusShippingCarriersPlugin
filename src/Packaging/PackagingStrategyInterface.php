<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Packaging;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException;
use Sylius\Component\Shipping\Model\ShipmentInterface;

/**
 * Groups the units of a shipment into the packages that are quoted. The plugin depends only on
 * this interface, so another strategy can replace the default one.
 *
 * An implementation must be deterministic: the packages are computed again when the order is completed,
 * and they are stored only because they match the quoted ones.
 */
interface PackagingStrategyInterface
{
    /**
     * @return non-empty-list<Package> Measured in the origin's units
     *
     * @throws UnpackableShipmentException When the shipment cannot be quoted: a variant has no weight,
     *                                     or a unit fits no box, not even on its own
     */
    public function pack(ShipmentInterface $shipment, CarrierShippingOriginInterface $origin): array;
}
