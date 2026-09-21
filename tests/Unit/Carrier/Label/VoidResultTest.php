<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\VoidResult;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use PHPUnit\Framework\TestCase;

final class VoidResultTest extends TestCase
{
    /**
     * A refusal says so and says why. Reading it as a success is what makes a warehouse ship a parcel the
     * shop believes was cancelled.
     */
    public function testARefusalIsNotAVoidAndCarriesItsReason(): void
    {
        $refused = VoidResult::refused('The shipment has already been picked up.');

        self::assertFalse($refused->voided);
        self::assertSame('The shipment has already been picked up.', $refused->reason);
    }

    public function testAVoidHasNothingToExplain(): void
    {
        $voided = VoidResult::voided();

        self::assertTrue($voided->voided);
        self::assertNull($voided->reason);
    }

    /**
     * Customs is told about a shipment that leaves its country, and about no other.
     */
    public function testAShipmentCrossesABorderWhenItsCountriesDiffer(): void
    {
        self::assertFalse($this->request('US', 'US')->crossesABorder());
        self::assertTrue($this->request('US', 'CA')->crossesABorder());
    }

    private function request(string $origin, string $destination): ShipmentRequest
    {
        return new ShipmentRequest(
            new Address($origin, '60601', 'Chicago', '1 Main St', 'IL'),
            new Address($destination, '98101', 'Seattle', '500 Pine St', 'WA'),
            '03',
            [new ShipmentPackage(new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', []))],
            'PDF',
            'the shop reference',
        );
    }
}
