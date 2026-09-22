<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;

/**
 * Everything a carrier needs to issue the labels of one shipment.
 */
final readonly class ShipmentRequest
{
    /**
     * @param string $serviceCode The carrier's own code for the service the buyer was charged for
     * @param non-empty-list<ShipmentPackage> $packages One entry per package, in the order they were packed
     * @param string $labelFormat What to ask the carrier to print, for instance PDF or ZPL
     * @param string $ownReference What the plugin calls this shipment. It is the only name it controls before
     *                             the carrier answers, and therefore the only one it can ask about afterwards
     *                             when no answer came back
     * @param CustomsInvoice|null $customsInvoice What the carrier prints the commercial invoice from. Null when
     *                                            nothing crosses a border
     */
    public function __construct(
        public Address $origin,
        public Address $destination,
        public string $serviceCode,
        public array $packages,
        public string $labelFormat,
        public string $ownReference,
        public ?CustomsInvoice $customsInvoice = null,
    ) {
    }

    /**
     * Whether customs has to be told anything at all: a shipment that never leaves its country is not
     * declared.
     */
    public function crossesABorder(): bool
    {
        return $this->origin->countryCode !== $this->destination->countryCode;
    }
}
