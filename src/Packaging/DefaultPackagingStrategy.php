<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Packaging;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierPackageBoxRepositoryInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Sylius\Component\Shipping\Model\ShipmentUnitInterface;
use Webmozart\Assert\Assert;

/**
 * The strategy the plugin ships with. It goes through the units from the largest to the smallest and keeps
 * a single package open, which it closes when the next unit would take it over the maximum weight or out
 * of every box. Without boxes for the origin, the units are split by weight into fallback
 * packages.
 *
 * A shipment it cannot pack is logged with the reason before the exception leaves.
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
        private readonly LoggerInterface $logger,
    ) {
    }

    public function pack(ShipmentInterface $shipment, CarrierShippingOriginInterface $origin): array
    {
        try {
            $units = $this->measure($shipment);
            $boxes = $this->boxRepository->findApplicableToOrigin($origin);

            return [] === $boxes ? $this->packWithoutBoxes($units, $origin) : $this->packInBoxes($units, $boxes, $origin);
        } catch (UnpackableShipmentException $exception) {
            // At error level: below it, a production log may never write the entry.
            $this->logger->error('The shipment cannot be packed, so it cannot be quoted: {reason}', [
                'reason' => $exception->getMessage(),
                'shipment_id' => $shipment->getId(),
            ]);

            throw $exception;
        }
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

            // Not even on its own: no fallback package hides it.
            $box = $this->boxSelector->select($unit->volume(), $unit->weight, $origin->getMaxPackageWeight(), $boxes)
                ?? throw new UnpackableShipmentException(sprintf(
                    '%s fits no box of the origin on its own, by volume or by weight.',
                    $this->describe($unit->unit),
                ));
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
                throw new UnpackableShipmentException(sprintf(
                    '%s weighs %s %s on its own, over the maximum package weight of %s %s.',
                    $this->describe($unit->unit),
                    $unit->weight,
                    $origin->getWeightUnit(),
                    $maxWeight,
                    $origin->getWeightUnit(),
                ));
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
     * @return non-empty-list<MeasuredUnit> From the largest to the smallest
     */
    private function measure(ShipmentInterface $shipment): array
    {
        $units = [];

        foreach ($shipment->getUnits() as $unit) {
            $shippable = $unit->getShippable();
            $weight = $this->declared($shippable?->getShippingWeight());
            if (null === $weight) {
                throw new UnpackableShipmentException(sprintf('%s has no weight declared.', $this->describe($unit)));
            }

            $measures = [
                'width' => $this->declared($shippable?->getShippingWidth()),
                'height' => $this->declared($shippable?->getShippingHeight()),
                'depth' => $this->declared($shippable?->getShippingDepth()),
            ];
            ['width' => $width, 'height' => $height, 'depth' => $depth] = $measures;
            if (null === $width || null === $height || null === $depth) {
                throw new UnpackableShipmentException(sprintf(
                    '%s has no %s declared.',
                    $this->describe($unit),
                    implode(' or ', array_keys(array_filter($measures, static fn (?float $measure): bool => null === $measure))),
                ));
            }

            $units[] = new MeasuredUnit($unit, $weight, $width, $height, $depth);
        }

        if ([] === $units) {
            throw new UnpackableShipmentException('The shipment has no units to pack.');
        }

        // Two units that swap places here have the same measures, so they give the same packages.
        usort($units, static fn (MeasuredUnit $one, MeasuredUnit $other): int => [$other->volume(), $other->weight, $other->length, $other->width, $other->height]
            <=> [$one->volume(), $one->weight, $one->length, $one->width, $one->height]);

        return $units;
    }

    /**
     * No unit weighs or measures 0, so a 0 is a value nobody declared.
     */
    private function declared(?float $value): ?float
    {
        return null !== $value && $value > 0.0 ? $value : null;
    }

    private function describe(ShipmentUnitInterface $unit): string
    {
        $shippable = $unit->getShippable();

        return $shippable instanceof ProductVariantInterface ? sprintf('The variant "%s"', (string) $shippable->getCode()) : 'A unit';
    }
}
