<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

/**
 * The label a carrier issued for one package, as it came back: the bytes, not a path. Where it is kept is the
 * plugin's business, not the carrier's.
 */
final readonly class IssuedLabel
{
    /**
     * @param int $position Which package of the shipment this is the label of, counting from zero
     * @param string $format What the carrier printed it as, for instance PDF or ZPL
     * @param string $contents The file itself
     */
    public function __construct(
        public int $position,
        public string $trackingNumber,
        public string $format,
        public string $contents,
    ) {
    }
}
