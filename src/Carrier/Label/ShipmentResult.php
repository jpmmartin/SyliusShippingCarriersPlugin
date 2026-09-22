<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

/**
 * What the carrier gave back for a shipment: what it calls it, one label per package and, for a shipment that
 * crosses a border, the document customs is handed.
 */
final readonly class ShipmentResult
{
    /**
     * @param string $carrierReference What the carrier calls this shipment. Without it nothing can be voided
     * @param non-empty-list<IssuedLabel> $labels One per package, in the order they were sent
     * @param CustomsDocument|null $customsDocument What customs is handed, when the carrier printed it
     */
    public function __construct(
        public string $carrierReference,
        public array $labels,
        public ?CustomsDocument $customsDocument = null,
    ) {
    }
}
