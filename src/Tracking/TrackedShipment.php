<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Tracking;

use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * A shipment the buyer can be told about: it travels with a carrier and carries a tracking number.
 *
 * What the carrier said is separate from the shipment itself, because the carrier may not have answered and the
 * buyer is still owed the number.
 *
 * @internal
 */
final readonly class TrackedShipment
{
    public function __construct(
        public ShipmentInterface $shipment,
        public string $trackingNumber,
        /** Null when the carrier could not be asked: the buyer gets the number and a notice, never an error. */
        public ?TrackingInfo $tracking,
    ) {
    }
}
