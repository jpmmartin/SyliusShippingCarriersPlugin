<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Sylius\Resource\Model\ResourceInterface;

/**
 * The label of one package: what the carrier gave back for it, and where the file lives.
 */
interface CarrierShipmentLabelInterface extends ResourceInterface
{
    public function getExport(): ?CarrierShipmentExportInterface;

    public function setExport(?CarrierShipmentExportInterface $export): void;

    /** Which package of the shipment this is the label of, counting from zero. */
    public function getPosition(): int;

    public function setPosition(int $position): void;

    /** Where the file sits in the plugin's storage. Null once it has been purged. */
    public function getPath(): ?string;

    public function setPath(?string $path): void;

    /** What the carrier printed it as: PDF, ZPL, PNG. */
    public function getFormat(): ?string;

    public function setFormat(?string $format): void;

    public function getTrackingNumber(): ?string;

    public function setTrackingNumber(?string $trackingNumber): void;

    /**
     * What this package is worth for customs, in hundredths, as Sylius keeps every amount.
     */
    public function getDeclaredValue(): ?int;

    public function setDeclaredValue(?int $declaredValue): void;

    public function getDeclaredValueCurrency(): ?string;

    public function setDeclaredValueCurrency(?string $currencyCode): void;

    /** When the file was deleted. The row stays: what went out is not forgotten because the file is gone. */
    public function getPurgedAt(): ?\DateTimeImmutable;

    public function setPurgedAt(?\DateTimeImmutable $purgedAt): void;

    public function isPurged(): bool;
}
