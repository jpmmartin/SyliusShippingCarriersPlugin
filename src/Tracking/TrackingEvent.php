<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Tracking;

final readonly class TrackingEvent
{
    /**
     * @param \DateTimeImmutable|null $occurredAt Null when the carrier gives no usable date
     * @param string|null $location As the carrier describes it, for instance a city and a country
     */
    public function __construct(
        public ?\DateTimeImmutable $occurredAt,
        public string $description,
        public ?string $location = null,
    ) {
    }
}
