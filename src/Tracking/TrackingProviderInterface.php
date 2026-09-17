<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Tracking;

use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * The only way to a carrier's tracking. The carrier is the one of the shipment's shipping method, so nobody has to
 * say which it is.
 */
interface TrackingProviderInterface
{
    /**
     * Null when there is nothing to tell: the shipment has no tracking number, its shipping method is not a
     * carrier's, or the carrier could not be asked.
     */
    public function track(ShipmentInterface $shipment): ?TrackingInfo;
}
