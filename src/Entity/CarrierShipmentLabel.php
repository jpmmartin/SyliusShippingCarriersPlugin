<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The label of one package. A shipment of three packages has three of these.
 */
#[ORM\Entity]
#[ORM\Table(name: 'jpmmartin_carrier_shipment_label')]
// Named explicitly so the mapping and the migration agree (see CarrierShippingOrigin).
#[ORM\UniqueConstraint(name: 'uniq_jpmmartin_carrier_label_export_position', columns: ['export_id', 'position'])]
class CarrierShipmentLabel implements CarrierShipmentLabelInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CarrierShipmentExport::class, inversedBy: 'labels')]
    #[ORM\JoinColumn(name: 'export_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?CarrierShipmentExportInterface $export = null;

    #[ORM\Column(type: 'integer')]
    protected int $position = 0;

    /**
     * Nullable, and not because a label may be issued without a file: it is emptied when the file is purged,
     * so nothing points at bytes that are no longer there.
     */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $path = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    protected ?string $format = null;

    #[ORM\Column(name: 'tracking_number', type: 'string', nullable: true)]
    protected ?string $trackingNumber = null;

    /** In hundredths, as Sylius keeps every amount. */
    #[ORM\Column(name: 'declared_value', type: 'integer', nullable: true)]
    protected ?int $declaredValue = null;

    #[ORM\Column(name: 'declared_value_currency', type: 'string', length: 3, nullable: true)]
    protected ?string $declaredValueCurrency = null;

    #[ORM\Column(name: 'purged_at', type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $purgedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExport(): ?CarrierShipmentExportInterface
    {
        return $this->export;
    }

    public function setExport(?CarrierShipmentExportInterface $export): void
    {
        $this->export = $export;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): void
    {
        $this->path = $path;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function setFormat(?string $format): void
    {
        $this->format = $format;
    }

    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    public function setTrackingNumber(?string $trackingNumber): void
    {
        $this->trackingNumber = $trackingNumber;
    }

    public function getDeclaredValue(): ?int
    {
        return $this->declaredValue;
    }

    public function setDeclaredValue(?int $declaredValue): void
    {
        $this->declaredValue = $declaredValue;
    }

    public function getDeclaredValueCurrency(): ?string
    {
        return $this->declaredValueCurrency;
    }

    public function setDeclaredValueCurrency(?string $currencyCode): void
    {
        $this->declaredValueCurrency = $currencyCode;
    }

    public function getPurgedAt(): ?\DateTimeImmutable
    {
        return $this->purgedAt;
    }

    public function setPurgedAt(?\DateTimeImmutable $purgedAt): void
    {
        $this->purgedAt = $purgedAt;
    }

    public function isPurged(): bool
    {
        return null !== $this->purgedAt;
    }
}
