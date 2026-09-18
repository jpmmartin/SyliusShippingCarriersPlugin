<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

/**
 * What the carrier gave back for a shipment: what it calls it, and one label per package.
 */
final readonly class ShipmentResult
{
    /**
     * @param string $carrierReference What the carrier calls this shipment. Without it nothing can be voided
     * @param non-empty-list<IssuedLabel> $labels One per package, in the order they were sent
     */
    public function __construct(
        public string $carrierReference,
        public array $labels,
    ) {
    }
}
