<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Tracking;

/**
 * What the carrier reports about a shipment: its current status and the events it knows of.
 */
final readonly class TrackingInfo
{
    /**
     * The newest first, whatever order the carrier sent them in.
     *
     * @var list<TrackingEvent>
     */
    public array $events;

    /**
     * @param string|null $status The carrier's description of the current status
     * @param list<TrackingEvent> $events In any order
     */
    public function __construct(
        public string $trackingNumber,
        public ?string $status,
        array $events,
    ) {
        // The order is settled here and not in each adapter: the carriers disagree on it, and a fixture written
        // from their documentation is a bad place to guess it. Events with no usable date keep the order the
        // carrier gave them, after the dated ones.
        usort($events, static function (TrackingEvent $first, TrackingEvent $second): int {
            if (null === $first->occurredAt || null === $second->occurredAt) {
                return (null === $first->occurredAt ? 1 : 0) <=> (null === $second->occurredAt ? 1 : 0);
            }

            return $second->occurredAt <=> $first->occurredAt;
        });

        $this->events = array_values($events);
    }
}
