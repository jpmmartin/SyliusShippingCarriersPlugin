<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Packaging;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\FallbackPackager;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\MeasuredUnit;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Sylius\Component\Shipping\Model\ShipmentUnitInterface;

final class FallbackPackagerTest extends TestCase
{
    /**
     * The property that makes the estimate safe (CA-39), over many random contents.
     */
    public function testThePackageIsNeverSmallerThanItsContents(): void
    {
        $random = new Randomizer(new Mt19937(39));
        $shipmentUnit = $this->createStub(ShipmentUnitInterface::class);
        $side = static fn (): float => $random->getInt(1, 10000) / 100;

        for ($case = 0; $case < 1000; ++$case) {
            $units = [];
            $contentVolume = 0.0;
            $count = $random->getInt(1, 8);
            do {
                $unit = new MeasuredUnit($shipmentUnit, 1.0, $side(), $side(), $side());
                $units[] = $unit;
                $contentVolume += $unit->volume();
            } while (count($units) < $count);

            $package = (new FallbackPackager())->pack($units, $this->origin());

            // A relative margin only absorbs float rounding when the package fits its contents exactly.
            self::assertGreaterThanOrEqual(
                $contentVolume * (1 - 1e-12),
                $package->length * $package->width * $package->height,
                sprintf('Case %d packs %d units.', $case, $count),
            );
        }
    }

    public function testTheUnitsAreStackedOnTheirShortestSide(): void
    {
        // A plate and a rod: stacked flat they take 10 × 10 × 2.
        $plate = $this->unit(1.5, 1.0, 10.0, 10.0);
        $rod = $this->unit(0.5, 10.0, 1.0, 1.0);

        $package = (new FallbackPackager())->pack([$plate, $rod], $this->origin());

        self::assertSame([10.0, 10.0, 2.0], [$package->length, $package->width, $package->height]);
    }

    public function testTheMeasuresOfAUnitGiveTheSamePackageInAnyOrder(): void
    {
        $packager = new FallbackPackager();
        $packages = [];

        foreach ([[2.0, 5.0, 10.0], [10.0, 2.0, 5.0], [5.0, 10.0, 2.0]] as [$side, $otherSide, $lastSide]) {
            $package = $packager->pack([$this->unit(1.0, $side, $otherSide, $lastSide)], $this->origin());
            $packages[] = [$package->length, $package->width, $package->height];
        }

        self::assertSame([[10.0, 5.0, 2.0], [10.0, 5.0, 2.0], [10.0, 5.0, 2.0]], $packages);
    }

    public function testThePackageUsesNoBoxAndCarriesTheWeightAndTheUnitsOfItsContents(): void
    {
        $first = $this->unit(1.5, 1.0, 1.0, 1.0);
        $second = $this->unit(2.25, 1.0, 1.0, 1.0);

        $package = (new FallbackPackager())->pack([$first, $second], $this->origin('kg', 'cm'));

        self::assertNull($package->boxName);
        self::assertSame(3.75, $package->weight);
        self::assertSame('kg', $package->weightUnit);
        self::assertSame('cm', $package->dimensionUnit);
        self::assertSame([$first->unit, $second->unit], $package->units);
    }

    private function unit(float $weight, float $side, float $otherSide, float $lastSide): MeasuredUnit
    {
        return new MeasuredUnit($this->createStub(ShipmentUnitInterface::class), $weight, $side, $otherSide, $lastSide);
    }

    private function origin(string $weightUnit = 'lb', string $dimensionUnit = 'in'): CarrierShippingOrigin
    {
        $origin = new CarrierShippingOrigin();
        $origin->setWeightUnit($weightUnit);
        $origin->setDimensionUnit($dimensionUnit);

        return $origin;
    }
}
