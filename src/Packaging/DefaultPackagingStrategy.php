<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Packaging;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierPackageBoxRepositoryInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Sylius\Component\Shipping\Model\ShipmentUnitInterface;
use Webmozart\Assert\Assert;

/**
 * The strategy the plugin ships with. It goes through the units from the largest to the smallest and keeps
 * a single package open, which it closes when the next unit would take it over the maximum weight or out
 * of every box (CA-14, D-20). Without boxes for the origin, the units are split by weight into fallback
 * packages (D-21).
 */
final class DefaultPackagingStrategy implements PackagingStrategyInterface
{
    /**
     * @param CarrierPackageBoxRepositoryInterface<CarrierPackageBoxInterface> $boxRepository
     */
    public function __construct(
        private readonly CarrierPackageBoxRepositoryInterface $boxRepository,
        private readonly BoxSelector $boxSelector,
        private readonly FallbackPackager $fallbackPackager,
    ) {
    }

    public function pack(ShipmentInterface $shipment, CarrierShippingOriginInterface $origin): array
    {
        $units = $this->measure($shipment);
        $boxes = $this->boxRepository->findApplicableToOrigin($origin);

        return [] === $boxes ? $this->packWithoutBoxes($units, $origin) : $this->packInBoxes($units, $boxes, $origin);
    }

    /**
     * @param non-empty-list<MeasuredUnit> $units
     * @param non-empty-list<CarrierPackageBoxInterface> $boxes
     *
     * @return non-empty-list<Package>
     */
    private function packInBoxes(array $units, array $boxes, CarrierShippingOriginInterface $origin): array
    {
        $packages = [];
        $contents = [];
        $volume = 0.0;
        $weight = 0.0;
        $box = null;

        foreach ($units as $unit) {
            if (null !== $box) {
                $biggerBox = $this->boxSelector->select($volume + $unit->volume(), $weight + $unit->weight, $origin->getMaxPackageWeight(), $boxes);
                if (null !== $biggerBox) {
                    $contents[] = $unit;
                    $volume += $unit->volume();
                    $weight += $unit->weight;
                    $box = $biggerBox;

                    continue;
                }

                Assert::isNonEmptyList($contents);
                $packages[] = $this->packageInBox($contents, $weight, $box, $origin);
            }

            // Not even on its own: no fallback package hides it (CA-40).
            $box = $this->boxSelector->select($unit->volume(), $unit->weight, $origin->getMaxPackageWeight(), $boxes)
                ?? throw new UnpackableShipmentException(sprintf('%s fits no box of the origin, not even on its own.', $this->describe($unit->unit)));
            $contents = [$unit];
            $volume = $unit->volume();
            $weight = $unit->weight;
        }

        Assert::notNull($box);
        Assert::isNonEmptyList($contents);
        $packages[] = $this->packageInBox($contents, $weight, $box, $origin);

        return $packages;
    }

    /**
     * @param non-empty-list<MeasuredUnit> $units
     *
     * @return non-empty-list<Package>
     */
    private function packWithoutBoxes(array $units, CarrierShippingOriginInterface $origin): array
    {
        $maxWeight = $origin->getMaxPackageWeight();
        $packages = [];
        $contents = [];
        $weight = 0.0;

        foreach ($units as $unit) {
            if ($unit->weight > $maxWeight) {
                throw new UnpackableShipmentException(sprintf('%s weighs more than the maximum package weight on its own.', $this->describe($unit->unit)));
            }

            if ([] !== $contents && $weight + $unit->weight > $maxWeight) {
                $packages[] = $this->fallbackPackager->pack($contents, $origin);
                $contents = [];
                $weight = 0.0;
            }

            $contents[] = $unit;
            $weight += $unit->weight;
        }

        Assert::isNonEmptyList($contents);
        $packages[] = $this->fallbackPackager->pack($contents, $origin);

        return $packages;
    }

    /**
     * @param non-empty-list<MeasuredUnit> $contents
     */
    private function packageInBox(array $contents, float $weight, CarrierPackageBoxInterface $box, CarrierShippingOriginInterface $origin): Package
    {
        // The selector only picks complete boxes.
        $name = $box->getName();
        $length = $box->getOuterLength();
        $width = $box->getOuterWidth();
        $height = $box->getOuterHeight();
        $emptyWeight = $box->getEmptyWeight();
        Assert::notNull($name);
        Assert::notNull($length);
        Assert::notNull($width);
        Assert::notNull($height);
        Assert::notNull($emptyWeight);

        return new Package(
            $name,
            $length,
            $width,
            $height,
            $origin->getDimensionUnit(),
            $weight + $emptyWeight,
            $origin->getWeightUnit(),
            array_map(static fn (MeasuredUnit $unit): ShipmentUnitInterface => $unit->unit, $contents),
        );
    }

    /**
     * @return non-empty-list<MeasuredUnit> From the largest to the smallest (D-20)
     */
    private function measure(ShipmentInterface $shipment): array
    {
        $units = [];

        foreach ($shipment->getUnits() as $unit) {
            $shippable = $unit->getShippable();
            $weight = $shippable?->getShippingWeight();
            $width = $shippable?->getShippingWidth();
            $height = $shippable?->getShippingHeight();
            $depth = $shippable?->getShippingDepth();
            if (null === $weight || null === $width || null === $height || null === $depth) {
                throw new UnpackableShipmentException(sprintf('%s has no weight or no measures declared.', $this->describe($unit)));
            }

            $units[] = new MeasuredUnit($unit, $weight, $width, $height, $depth);
        }

        if ([] === $units) {
            throw new UnpackableShipmentException('The shipment has no units to pack.');
        }

        // Two units that swap places here have the same measures, so they give the same packages (D-9).
        usort($units, static fn (MeasuredUnit $one, MeasuredUnit $other): int => [$other->volume(), $other->weight, $other->length, $other->width, $other->height]
            <=> [$one->volume(), $one->weight, $one->length, $one->width, $one->height]);

        return $units;
    }

    private function describe(ShipmentUnitInterface $unit): string
    {
        $shippable = $unit->getShippable();

        return $shippable instanceof ProductVariantInterface ? sprintf('The variant "%s"', (string) $shippable->getCode()) : 'A unit';
    }
}
