<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\ShipmentLabels;
use PHPUnit\Framework\TestCase;

/**
 * What the admin offers to download for a shipment. The screen keeps saying what was shipped long after the
 * files are gone, so what is offered and what is recorded are two different questions.
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

    public function testTheLabelsOfAnIssuedShipmentAreOffered(): void
    {
        $export = $this->export(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->addLabel($this->label('1Z9991'));
        $export->addLabel($this->label('1Z9992'));

        self::assertCount(2, (new ShipmentLabels('ups', $export))->downloadable());
    }

    /**
     * The purge deletes the file and keeps the row, so the row is still there to be offered. Offering it hands
     * an administrator a download that cannot be served: the file it names is gone.
     */
    public function testALabelWhoseFileWasPurgedIsNotOffered(): void
    {
        $export = $this->export(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->addLabel($this->label('1Z9991'));
        $purged = $this->label('1Z9992');
        $purged->setPurgedAt(new \DateTimeImmutable('2026-09-22 10:00:00'));
        $export->addLabel($purged);

        $offered = (new ShipmentLabels('ups', $export))->downloadable();

        self::assertCount(1, $offered);
        self::assertSame('1Z9991', $offered[0]->getTrackingNumber());
    }

    /**
     * The shipment is still on record when every file of it is gone: the admin stops offering downloads, and
     * nothing else about it changes.
     */
    public function testAShipmentPurgedWholeOffersNothingAndIsStillIssued(): void
    {
        $export = $this->export(CarrierShipmentExportInterface::STATE_ISSUED);
        $label = $this->label('1Z9991');
        $label->setPurgedAt(new \DateTimeImmutable('2026-09-22 10:00:00'));
        $export->addLabel($label);
        $export->setCustomsDocumentPurgedAt(new \DateTimeImmutable('2026-09-22 10:00:00'));

        $labels = new ShipmentLabels('ups', $export);

        self::assertSame([], $labels->downloadable());
        self::assertFalse($labels->hasCustomsDocument());
        self::assertTrue($labels->isIssued());
        self::assertFalse($labels->canBeIssued());
    }

    private function label(string $trackingNumber): CarrierShipmentLabelInterface
    {
        $label = new CarrierShipmentLabel();
        $label->setPosition(0);
        $label->setPath(sprintf('labels/42/%s-0.gif', $trackingNumber));
        $label->setFormat('GIF');
        $label->setTrackingNumber($trackingNumber);

        return $label;
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
