<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Tracking;

use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingEvent;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;
use PHPUnit\Framework\TestCase;

final class TrackingInfoTest extends TestCase
{
    /**
     * The carriers disagree on the order they report their events in, and the real answer of one of them
     * contradicted what its documentation suggested. The buyer sees the newest first whatever they send.
     */
    public function testTheEventsAreReportedNewestFirstWhateverOrderTheyCameIn(): void
    {
        $oldest = new TrackingEvent(new \DateTimeImmutable('2026-09-15 08:02:00'), 'Shipment information sent');
        $middle = new TrackingEvent(new \DateTimeImmutable('2026-09-16 11:30:00'), 'Out for delivery');
        $newest = new TrackingEvent(new \DateTimeImmutable('2026-09-17 10:15:00'), 'Delivered');

        foreach ([[$oldest, $middle, $newest], [$newest, $middle, $oldest], [$middle, $newest, $oldest]] as $order) {
            $tracking = new TrackingInfo('1Z999AA10123456784', 'Delivered', $order);

            self::assertSame(
                ['Delivered', 'Out for delivery', 'Shipment information sent'],
                array_map(static fn (TrackingEvent $event): string => $event->description, $tracking->events),
            );
        }
    }

    /**
     * A carrier that gives no usable date for an event still has something to say about it.
     */
    public function testEventsWithoutADateComeAfterTheDatedOnesAndKeepTheirOrder(): void
    {
        $tracking = new TrackingInfo('1Z999AA10123456784', null, [
            new TrackingEvent(null, 'First undated'),
            new TrackingEvent(new \DateTimeImmutable('2026-09-15 08:02:00'), 'Oldest'),
            new TrackingEvent(null, 'Second undated'),
            new TrackingEvent(new \DateTimeImmutable('2026-09-17 10:15:00'), 'Newest'),
        ]);

        self::assertSame(
            ['Newest', 'Oldest', 'First undated', 'Second undated'],
            array_map(static fn (TrackingEvent $event): string => $event->description, $tracking->events),
        );
    }

    public function testAShipmentWithoutEventsIsFine(): void
    {
        self::assertSame([], (new TrackingInfo('1Z999AA10123456784', 'In transit', []))->events);
    }
}
