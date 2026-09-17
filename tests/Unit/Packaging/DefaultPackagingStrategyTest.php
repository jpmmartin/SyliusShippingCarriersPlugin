<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Packaging;

use Doctrine\Common\Collections\ArrayCollection;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\BoxSelector;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\DefaultPackagingStrategy;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\FallbackPackager;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierPackageBoxRepositoryInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Sylius\Component\Shipping\Model\ShipmentUnitInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

final class DefaultPackagingStrategyTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testAShipmentThatFitsInOnePackage(): void
    {
        $first = $this->unit(5.0, 10.0, 10.0, 10.0);
        $second = $this->unit(5.0, 10.0, 10.0, 10.0);

        $packages = $this->strategy([$this->box('Medium', [20.0, 20.0, 20.0], [21.0, 21.0, 21.0], 1.0)])
            ->pack($this->shipment($first, $second), $this->origin());

        self::assertSame([['Medium', 21.0, 21.0, 21.0, 11.0]], $this->measures($packages));
        self::assertSame([$first, $second], $packages[0]->units);
    }

    public function testANewPackageOpensWhenTheNextUnitWouldGoOverTheMaximumWeight(): void
    {
        $shipment = $this->shipment($this->unit(8.0, 5.0, 5.0, 5.0), $this->unit(8.0, 5.0, 5.0, 5.0), $this->unit(8.0, 5.0, 5.0, 5.0));

        // Two units and the empty box weigh 17; a third one would make 25, over the maximum of 20.
        $packages = $this->strategy([$this->box('Big', [30.0, 30.0, 30.0], [31.0, 31.0, 31.0], 1.0)])
            ->pack($shipment, $this->origin(20.0));

        self::assertSame([['Big', 31.0, 31.0, 31.0, 17.0], ['Big', 31.0, 31.0, 31.0, 9.0]], $this->measures($packages));
    }

    public function testANewPackageOpensWhenTheNextUnitFitsNoBox(): void
    {
        $shipment = $this->shipment($this->unit(1.0, 10.0, 10.0, 10.0), $this->unit(1.0, 10.0, 10.0, 10.0), $this->unit(1.0, 10.0, 10.0, 10.0));

        // The box holds two units of 1000 in its 2000.
        $packages = $this->strategy([$this->box('Tall', [10.0, 10.0, 20.0], [11.0, 11.0, 21.0], 0.5)])
            ->pack($shipment, $this->origin());

        self::assertSame([['Tall', 11.0, 11.0, 21.0, 2.5], ['Tall', 11.0, 11.0, 21.0, 1.5]], $this->measures($packages));
    }

    public function testAGrowingPackageMovesToABiggerBox(): void
    {
        $boxes = [
            $this->box('Small', [10.0, 10.0, 10.0], [11.0, 11.0, 11.0], 0.5),
            $this->box('Large', [10.0, 10.0, 20.0], [11.0, 11.0, 21.0], 0.5),
        ];

        $packages = $this->strategy($boxes)->pack($this->shipment($this->unit(1.0, 10.0, 10.0, 10.0), $this->unit(1.0, 10.0, 10.0, 10.0)), $this->origin());

        self::assertSame([['Large', 11.0, 11.0, 21.0, 2.5]], $this->measures($packages));
    }

    /**
     * Taken in the order they arrive, these units would give three units in the large box
     * and one in the small one.
     */
    public function testTheSameUnitsInAnotherOrderGiveTheSamePackages(): void
    {
        $cube = $this->unit(2.0, 10.0, 10.0, 10.0);
        $smallCube = $this->unit(1.0, 5.0, 5.0, 5.0);
        $slab = $this->unit(1.0, 10.0, 10.0, 5.0);
        $heavyCube = $this->unit(3.0, 8.0, 8.0, 8.0);
        $strategy = $this->strategy([
            $this->box('Small', [10.0, 10.0, 10.0], [11.0, 11.0, 11.0], 0.5),
            $this->box('Large', [10.0, 10.0, 20.0], [11.0, 11.0, 21.0], 0.5),
        ]);

        foreach ([[$cube, $smallCube, $slab, $heavyCube], [$heavyCube, $slab, $smallCube, $cube]] as $units) {
            $packages = $strategy->pack($this->shipment(...$units), $this->origin());

            self::assertSame([['Large', 11.0, 11.0, 21.0, 5.5], ['Small', 11.0, 11.0, 11.0, 2.5]], $this->measures($packages));
            self::assertSame([[$cube, $heavyCube], [$slab, $smallCube]], [$packages[0]->units, $packages[1]->units]);
        }
    }

    public function testWithoutBoxesTheUnitsAreSplitByWeightIntoFallbackPackages(): void
    {
        $large = $this->unit(6.0, 3.0, 3.0, 3.0);
        $medium = $this->unit(6.0, 2.0, 2.0, 2.0);
        $small = $this->unit(3.0, 1.0, 1.0, 1.0);

        $packages = $this->strategy([])->pack($this->shipment($small, $medium, $large), $this->origin(10.0));

        self::assertSame([[null, 3.0, 3.0, 3.0, 6.0], [null, 2.0, 2.0, 3.0, 9.0]], $this->measures($packages));
        self::assertSame([[$large], [$medium, $small]], [$packages[0]->units, $packages[1]->units]);
    }

    public function testAVariantWithoutWeightMakesTheShipmentUnpackable(): void
    {
        $this->assertUnpackable(
            'The variant "feather" has no weight declared.',
            $this->strategy([$this->box('Big', [30.0, 30.0, 30.0], [31.0, 31.0, 31.0], 1.0)]),
            $this->shipment($this->unit(null, 5.0, 5.0, 5.0, 'feather')),
        );
    }

    public function testAWeightOf0CountsAsNoWeight(): void
    {
        $this->assertUnpackable(
            'The variant "feather" has no weight declared.',
            $this->strategy([$this->box('Big', [30.0, 30.0, 30.0], [31.0, 31.0, 31.0], 1.0)]),
            $this->shipment($this->unit(0.0, 5.0, 5.0, 5.0, 'feather')),
        );
    }

    public function testAVariantWithoutAMeasureMakesTheShipmentUnpackable(): void
    {
        $this->assertUnpackable(
            'The variant "poster" has no depth declared.',
            $this->strategy([$this->box('Big', [30.0, 30.0, 30.0], [31.0, 31.0, 31.0], 1.0)]),
            $this->shipment($this->unit(1.0, 5.0, 5.0, 5.0), $this->unit(1.0, 20.0, 30.0, null, 'poster')),
        );
    }

    public function testAMeasureOf0CountsAsNoMeasure(): void
    {
        $this->assertUnpackable(
            'The variant "poster" has no width or height declared.',
            $this->strategy([]),
            $this->shipment($this->unit(1.0, 0.0, 0.0, 30.0, 'poster')),
        );
    }

    /**
     * The shipment does not fall back to a package without a box.
     */
    public function testAUnitThatFitsNoBoxOnItsOwnMakesTheShipmentUnpackable(): void
    {
        $this->assertUnpackable(
            'The variant "wardrobe" fits no box of the origin on its own, by volume or by weight.',
            $this->strategy([$this->box('Big', [30.0, 30.0, 30.0], [31.0, 31.0, 31.0], 1.0)]),
            $this->shipment($this->unit(1.0, 5.0, 5.0, 5.0), $this->unit(40.0, 60.0, 180.0, 50.0, 'wardrobe')),
        );
    }

    /**
     * Without boxes, the maximum package weight still holds.
     */
    public function testWithoutBoxesAUnitHeavierThanTheMaximumMakesTheShipmentUnpackable(): void
    {
        $this->assertUnpackable(
            'The variant "anvil" weighs 200 lb on its own, over the maximum package weight of 150 lb.',
            $this->strategy([]),
            $this->shipment($this->unit(1.0, 5.0, 5.0, 5.0), $this->unit(200.0, 10.0, 10.0, 20.0, 'anvil')),
        );
    }

    /**
     * @param list<CarrierPackageBox> $boxes
     */
    private function strategy(array $boxes): DefaultPackagingStrategy
    {
        /** @var CarrierPackageBoxRepositoryInterface<CarrierPackageBoxInterface>&Stub $repository */
        $repository = $this->createStub(CarrierPackageBoxRepositoryInterface::class);
        $repository->method('findApplicableToOrigin')->willReturn($boxes);

        return new DefaultPackagingStrategy($repository, new BoxSelector(), new FallbackPackager(), $this->logger);
    }

    /**
     * The reason is both the exception message and what is logged, at error level.
     */
    private function assertUnpackable(string $reason, DefaultPackagingStrategy $strategy, ShipmentInterface $shipment): void
    {
        try {
            $strategy->pack($shipment, $this->origin());
            self::fail('The shipment was packed.');
        } catch (UnpackableShipmentException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }

        self::assertSame(
            [[LogLevel::ERROR, 'The shipment cannot be packed, so it cannot be quoted: {reason}', ['reason' => $reason, 'shipment_id' => 7]]],
            $this->logger->records,
        );
    }

    private function shipment(ShipmentUnitInterface ...$units): ShipmentInterface
    {
        $shipment = $this->createStub(ShipmentInterface::class);
        $shipment->method('getId')->willReturn(7);
        $shipment->method('getUnits')->willReturn(new ArrayCollection(array_values($units)));

        return $shipment;
    }

    private function unit(?float $weight, ?float $width, ?float $height, ?float $depth, string $code = 'variant'): ShipmentUnitInterface
    {
        $variant = new ProductVariant();
        $variant->setCode($code);
        $variant->setWeight($weight);
        $variant->setWidth($width);
        $variant->setHeight($height);
        $variant->setDepth($depth);

        $unit = $this->createStub(ShipmentUnitInterface::class);
        $unit->method('getShippable')->willReturn($variant);

        return $unit;
    }

    private function origin(float $maxPackageWeight = 150.0): CarrierShippingOrigin
    {
        $origin = new CarrierShippingOrigin();
        $origin->setMaxPackageWeight($maxPackageWeight);

        return $origin;
    }

    /**
     * @param array{float, float, float} $inner
     * @param array{float, float, float} $outer
     */
    private function box(string $name, array $inner, array $outer, float $emptyWeight): CarrierPackageBox
    {
        $box = new CarrierPackageBox();
        $box->setName($name);
        $box->setInnerLength($inner[0]);
        $box->setInnerWidth($inner[1]);
        $box->setInnerHeight($inner[2]);
        $box->setOuterLength($outer[0]);
        $box->setOuterWidth($outer[1]);
        $box->setOuterHeight($outer[2]);
        $box->setEmptyWeight($emptyWeight);
        $box->setMaxWeight(100.0);

        return $box;
    }

    /**
     * @param list<Package> $packages
     *
     * @return list<array{string|null, float, float, float, float}>
     */
    private function measures(array $packages): array
    {
        return array_map(static fn (Package $package): array => [$package->boxName, $package->length, $package->width, $package->height, $package->weight], $packages);
    }
}
