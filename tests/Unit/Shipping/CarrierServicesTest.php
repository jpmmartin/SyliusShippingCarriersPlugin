<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Shipping;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\CarrierServices;
use PHPUnit\Framework\TestCase;

final class CarrierServicesTest extends TestCase
{
    /**
     * A code such as "12" is an integer key in a PHP array; it must still compare with the code a shipping method
     * stores.
     */
    public function testCodesAreStringsEvenWhenTheyLookLikeNumbers(): void
    {
        $services = new CarrierServices(['ups' => ['03' => 'UPS Ground', '12' => 'A numeric code']]);

        self::assertSame(['03', '12'], $services->codes('ups'));
        self::assertSame('A numeric code', $services->name('ups', '12'));
    }

    public function testAServiceOutsideTheListOfItsCarrierHasNoName(): void
    {
        $services = new CarrierServices(['ups' => ['03' => 'UPS Ground'], 'fedex' => ['FEDEX_GROUND' => 'FedEx Ground']]);

        self::assertNull($services->name('ups', 'FEDEX_GROUND'));
        self::assertNull($services->name('ups', '3'));
        self::assertSame([], $services->codes('dhl'));
    }
}
