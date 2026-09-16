<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Packaging;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\BoxSelector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoxSelectorTest extends TestCase
{
    /**
     * @param list<CarrierPackageBox> $boxes
     */
    #[DataProvider('cases')]
    public function testItSelectsTheBox(float $contentVolume, float $contentWeight, float $maxPackageWeight, array $boxes, ?string $expected): void
    {
        $selected = (new BoxSelector())->select($contentVolume, $contentWeight, $maxPackageWeight, $boxes);

        self::assertSame($expected, $selected?->getName());
    }

    /**
     * Content volume, content weight, maximum package weight, boxes in catalog order, expected box.
     *
     * @return iterable<string, array{float, float, float, list<CarrierPackageBox>, string|null}>
     */
    public static function cases(): iterable
    {
        yield 'the contents fill the box exactly' => [960.0, 29.5, 150.0, [self::medium()], 'Medium'];

        yield 'it does not fit by volume' => [960.5, 1.0, 150.0, [self::medium()], null];

        yield 'it does not fit by the box weight, counting the empty box' => [960.0, 29.6, 150.0, [self::medium()], null];

        yield 'it does not fit by the package weight, counting the empty box' => [
            960.0, 149.8, 150.0, [self::box('Sturdy', [12.0, 10.0, 8.0], [13.0, 11.0, 9.0], 0.5, 200.0)], null,
        ];

        yield 'the smallest inner volume wins' => [
            500.0, 1.0, 150.0, [self::box('Large', [20.0, 10.0, 10.0], [21.0, 11.0, 11.0], 0.5, 30.0), self::medium()], 'Medium',
        ];

        yield 'a smaller box that cannot take the weight gives way to a larger one' => [
            500.0, 148.0, 150.0, [
                self::box('Heavy medium', [12.0, 10.0, 8.0], [13.0, 11.0, 9.0], 5.0, 200.0),
                self::box('Light large', [20.0, 10.0, 10.0], [21.0, 11.0, 11.0], 1.0, 200.0),
            ], 'Light large',
        ];

        yield 'on equal inner volume, the smaller outer volume wins' => [
            500.0, 1.0, 150.0, [
                self::box('Thick walls', [12.0, 10.0, 8.0], [16.0, 14.0, 12.0], 0.5, 30.0),
                self::box('Thin walls', [8.0, 10.0, 12.0], [9.0, 11.0, 13.0], 0.5, 30.0),
            ], 'Thin walls',
        ];

        yield 'on a full tie, the first box in the catalog wins' => [500.0, 1.0, 150.0, [self::medium('First'), self::medium('Second')], 'First'];

        yield 'without boxes there is nothing to select' => [1.0, 1.0, 150.0, [], null];

        yield 'a box with missing measures is skipped' => [500.0, 1.0, 150.0, [self::incomplete(), self::medium()], 'Medium'];
    }

    /**
     * Inner 12 × 10 × 8 = 960, outer 13 × 11 × 9, 0.5 empty, 30 at most.
     */
    private static function medium(string $name = 'Medium'): CarrierPackageBox
    {
        return self::box($name, [12.0, 10.0, 8.0], [13.0, 11.0, 9.0], 0.5, 30.0);
    }

    private static function incomplete(): CarrierPackageBox
    {
        $box = self::medium('Incomplete');
        $box->setInnerHeight(null);

        return $box;
    }

    /**
     * @param array{float, float, float} $inner
     * @param array{float, float, float} $outer
     */
    private static function box(string $name, array $inner, array $outer, float $emptyWeight, float $maxWeight): CarrierPackageBox
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
        $box->setMaxWeight($maxWeight);

        return $box;
    }
}
