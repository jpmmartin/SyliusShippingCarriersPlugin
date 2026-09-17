<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Packaging;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;

/**
 * The package declared when the origin has no box to use: the units stacked on their shortest
 * side, `max(length) × max(width) × sum(heights)`. Every unit fits in
 * `max(length) × max(width) × its height`, so the package is never smaller than its contents and the
 * possible error is quoting too much, never too little.
 */
final class FallbackPackager
{
    /**
     * @param non-empty-list<MeasuredUnit> $units
     */
    public function pack(array $units, CarrierShippingOriginInterface $origin): Package
    {
        $length = 0.0;
        $width = 0.0;
        $height = 0.0;
        $weight = 0.0;

        foreach ($units as $unit) {
            $length = max($length, $unit->length);
            $width = max($width, $unit->width);
            $height += $unit->height;
            // There is no box, so no empty weight to add.
            $weight += $unit->weight;
        }

        return new Package(
            null,
            $length,
            $width,
            $height,
            $origin->getDimensionUnit(),
            $weight,
            $origin->getWeightUnit(),
            array_map(static fn (MeasuredUnit $unit) => $unit->unit, $units),
        );
    }
}
