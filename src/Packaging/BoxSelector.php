<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Packaging;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;

/**
 * Picks the box for the contents of a package. Volumes are compared, not shapes: contents that fit by
 * volume but not by shape get a box smaller than the one they need.
 */
final class BoxSelector
{
    /**
     * The box with the smallest inner volume among those whose inner volume holds the contents
     * and whose maximum weight, like the package's, holds the contents plus the empty box.
     * On equal inner volume, the smaller outer volume wins, and then the box that comes first.
     *
     * @param list<CarrierPackageBoxInterface> $boxes In catalog order, the one findApplicableToOrigin() returns
     */
    public function select(float $contentVolume, float $contentWeight, float $maxPackageWeight, array $boxes): ?CarrierPackageBoxInterface
    {
        $selected = null;
        $selectedInnerVolume = \INF;
        $selectedOuterVolume = \INF;

        foreach ($boxes as $box) {
            $innerVolume = $this->volume($box->getInnerLength(), $box->getInnerWidth(), $box->getInnerHeight());
            $outerVolume = $this->volume($box->getOuterLength(), $box->getOuterWidth(), $box->getOuterHeight());
            $emptyWeight = $box->getEmptyWeight();
            $maxWeight = $box->getMaxWeight();
            if (null === $innerVolume || null === $outerVolume || null === $emptyWeight || null === $maxWeight) {
                // Validation rejects such a box, so none is ever stored.
                continue;
            }

            $weight = $contentWeight + $emptyWeight;
            if ($contentVolume > $innerVolume || $weight > $maxWeight || $weight > $maxPackageWeight) {
                continue;
            }

            // Only a strictly smaller box replaces the selected one, so on a full tie the first one stays.
            if ($innerVolume < $selectedInnerVolume || ($innerVolume === $selectedInnerVolume && $outerVolume < $selectedOuterVolume)) {
                $selected = $box;
                $selectedInnerVolume = $innerVolume;
                $selectedOuterVolume = $outerVolume;
            }
        }

        return $selected;
    }

    private function volume(?float $length, ?float $width, ?float $height): ?float
    {
        if (null === $length || null === $width || null === $height) {
            return null;
        }

        return $length * $width * $height;
    }
}
