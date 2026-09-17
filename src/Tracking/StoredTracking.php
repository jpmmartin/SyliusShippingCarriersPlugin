<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Tracking;

/**
 * What a carrier answered about a shipment, as kept in the tracking cache.
 *
 * Plain arrays rather than serialized objects of the plugin, so an update that changes these classes never leaves
 * entries behind that cannot be read.
 *
 * @internal
 */
final readonly class StoredTracking
{
    /**
     * Null when the cache holds something else, such as an entry written by another version of the plugin.
     */
    public static function fromCacheValue(mixed $value, string $trackingNumber): ?TrackingInfo
    {
        if (!is_array($value) || !is_array($value['events'] ?? null)) {
            return null;
        }

        $status = $value['status'] ?? null;
        if (null !== $status && !is_string($status)) {
            return null;
        }

        $events = [];
        foreach ($value['events'] as $event) {
            if (!is_array($event) || !is_string($event['description'] ?? null)) {
                return null;
            }

            $occurredAt = $event['occurred_at'] ?? null;
            $location = $event['location'] ?? null;
            if ((null !== $occurredAt && !is_string($occurredAt)) || (null !== $location && !is_string($location))) {
                return null;
            }

            $events[] = new TrackingEvent(null === $occurredAt ? null : new \DateTimeImmutable($occurredAt), $event['description'], $location);
        }

        return new TrackingInfo($trackingNumber, $status, $events);
    }

    /**
     * @return array{status: string|null, events: list<array{occurred_at: string|null, description: string, location: string|null}>}
     */
    public static function toCacheValue(TrackingInfo $tracking): array
    {
        return [
            'status' => $tracking->status,
            'events' => array_map(
                static fn (TrackingEvent $event): array => [
                    'occurred_at' => $event->occurredAt?->format(\DateTimeInterface::ATOM),
                    'description' => $event->description,
                    'location' => $event->location,
                ],
                $tracking->events,
            ),
        ];
    }
}
