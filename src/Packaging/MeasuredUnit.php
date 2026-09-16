<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Packaging;

use Sylius\Component\Shipping\Model\ShipmentUnitInterface;

/**
 * A shipment unit with its weight and measures, in the origin's units. Whatever order its measures come
 * in, its longest side is the length and its shortest one the height (D-18).
 */
final readonly class MeasuredUnit
{
    public float $length;

    public float $width;

    public float $height;

    public function __construct(
        public ShipmentUnitInterface $unit,
        public float $weight,
        float $side,
        float $otherSide,
        float $lastSide,
    ) {
        $sides = [$side, $otherSide, $lastSide];
        rsort($sides);

        $this->length = $sides[0];
        $this->width = $sides[1];
        $this->height = $sides[2];
    }

    public function volume(): float
    {
        return $this->length * $this->width * $this->height;
    }
}
