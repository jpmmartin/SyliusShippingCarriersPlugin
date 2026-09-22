<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

/**
 * The customs paperwork a carrier printed for a shipment, as it came back: one file for the whole shipment, not
 * one per package, since it declares what the shipment carries.
 */
final readonly class CustomsDocument
{
    /**
     * @param string $format What the carrier printed it as, for instance PDF
     * @param string $contents The file itself
     */
    public function __construct(
        public string $format,
        public string $contents,
    ) {
    }
}
