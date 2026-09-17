<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Tracking;

/**
 * What the carrier reports about a shipment: its current status and the events it knows of.
 */
final readonly class TrackingInfo
{
    /**
     * @param string|null $status The carrier's description of the current status
     * @param list<TrackingEvent> $events The newest first
     */
    public function __construct(
        public string $trackingNumber,
        public ?string $status,
        public array $events,
    ) {
    }
}
