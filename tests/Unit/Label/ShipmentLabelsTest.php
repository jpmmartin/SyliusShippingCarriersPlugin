<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\ShipmentLabels;
use PHPUnit\Framework\TestCase;

/**
 * Whether the admin offers the customs document of a shipment: under the rules of its labels.
 */
final class ShipmentLabelsTest extends TestCase
{
    public function testTheCustomsDocumentOfAnIssuedShipmentIsOffered(): void
    {
        self::assertTrue((new ShipmentLabels('ups', $this->export(CarrierShipmentExportInterface::STATE_ISSUED)))->hasCustomsDocument());
    }

    /**
     * The paperwork of a cancelled shipment declares a parcel the carrier no longer has.
     */
    public function testTheCustomsDocumentOfACancelledShipmentIsNotOffered(): void
    {
        self::assertFalse((new ShipmentLabels('ups', $this->export(CarrierShipmentExportInterface::STATE_VOIDED)))->hasCustomsDocument());
    }

    public function testAPurgedCustomsDocumentIsNotOffered(): void
    {
        $export = $this->export(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->setCustomsDocumentPurgedAt(new \DateTimeImmutable('2026-09-21 10:00:00'));

        self::assertFalse((new ShipmentLabels('ups', $export))->hasCustomsDocument());
    }

    public function testAShipmentWithoutACustomsDocumentOffersNone(): void
    {
        $export = $this->export(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->setCustomsDocumentPath(null);

        self::assertFalse((new ShipmentLabels('ups', $export))->hasCustomsDocument());
        self::assertFalse((new ShipmentLabels('ups'))->hasCustomsDocument());
    }

    /**
     * @param CarrierShipmentExportInterface::STATE_* $state
     */
    private function export(string $state): CarrierShipmentExportInterface
    {
        $export = new CarrierShipmentExport();
        $export->setState($state);
        $export->setCustomsDocumentPath('labels/42/customs-1Z999AA10123456784.pdf');
        $export->setCustomsDocumentFormat('PDF');

        return $export;
    }
}
