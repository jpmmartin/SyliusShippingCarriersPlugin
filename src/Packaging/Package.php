<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Packaging;

use Sylius\Component\Shipping\Model\ShipmentUnitInterface;

/**
 * A package as it is declared to the carrier, and what it carries. Everything stored for it once the
 * order is completed is copied from here.
 */
final readonly class Package
{
    /**
     * @param string|null $boxName Null for the fallback package, which uses no box
     * @param float $length Outer length of the box, in $dimensionUnit
     * @param float $width Outer width of the box, in $dimensionUnit
     * @param float $height Outer height of the box, in $dimensionUnit
     * @param float $weight The contents plus the empty box, in $weightUnit
     * @param list<ShipmentUnitInterface> $units The shipment units it carries
     */
    public function __construct(
        public ?string $boxName,
        public float $length,
        public float $width,
        public float $height,
        public string $dimensionUnit,
        public float $weight,
        public string $weightUnit,
        public array $units,
    ) {
    }
}
