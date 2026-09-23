<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Twig;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShipmentCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackedShipment;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * What the shop can tell the buyer about where the shipments of an order are.
 *
 * @internal
 */
final readonly class ShipmentTrackingRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private ShipmentCarrier $shipmentCarrier,
        private TrackingProviderInterface $trackingProvider,
    ) {
    }

    /**
     * Only the shipments there is something to say about: one that travels with another shipping method, or that has
     * no tracking number yet, is left out, and no carrier is asked about it.
     *
     * @return list<TrackedShipment>
     */
    public function ofOrder(OrderInterface $order): array
    {
        $tracked = [];
        foreach ($order->getShipments() as $shipment) {
            if (!$shipment instanceof ShipmentInterface) {
                continue;
            }

            $trackingNumber = trim((string) $shipment->getTracking());
            if ('' === $trackingNumber || null === $this->shipmentCarrier->of($shipment)) {
                continue;
            }

            $tracked[] = new TrackedShipment($shipment, $trackingNumber, $this->trackingProvider->track($shipment));
        }

        return $tracked;
    }
}
