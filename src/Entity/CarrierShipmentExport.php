<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * What happened when a shipment was handed to its carrier: one row per exported shipment, with the labels it
 * produced hanging off it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'jpmmartin_carrier_shipment_export')]
// Named explicitly so the mapping and the migration agree (see CarrierShippingOrigin).
#[ORM\UniqueConstraint(name: 'uniq_jpmmartin_carrier_export_shipment', columns: ['shipment_id'])]
class CarrierShipmentExport implements CarrierShipmentExportInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    /**
     * The target entity is the Shipping component interface, the one registered as the `sylius.shipment`
     * resource and therefore the one DoctrineTargetEntitiesResolverPass knows how to map.
     *
     * Unique on purpose: a shipment is exported once.
     */
    #[ORM\ManyToOne(targetEntity: \Sylius\Component\Shipping\Model\ShipmentInterface::class)]
    #[ORM\JoinColumn(name: 'shipment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?ShipmentInterface $shipment = null;

    /** @var CarrierShipmentExportInterface::STATE_* */
    #[ORM\Column(type: 'string', length: 16)]
    protected string $state = self::STATE_PENDING;

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    protected ?string $carrier = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    protected ?string $environment = null;

    #[ORM\Column(name: 'carrier_reference', type: 'string', nullable: true)]
    protected ?string $carrierReference = null;

    /**
     * What the plugin called the last attempt, which is what it sent the carrier as its own reference. It is
     * the only name it controls before the carrier answers, so it is the only one left to ask about when no
     * answer comes back.
     */
    #[ORM\Column(name: 'own_reference', type: 'string', length: 64, nullable: true)]
    protected ?string $ownReference = null;

    #[ORM\Column(name: 'issued_at', type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $issuedAt = null;

    /**
     * Who issued it, kept as they were named at the time and not as a link to a user: the record of a shipment
     * that went out must survive whoever issued it leaving the company.
     */
    #[ORM\Column(name: 'issued_by', type: 'string', nullable: true)]
    protected ?string $issuedBy = null;

    #[ORM\Column(name: 'voided_at', type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $voidedAt = null;

    #[ORM\Column(name: 'voided_by', type: 'string', nullable: true)]
    protected ?string $voidedBy = null;

    #[ORM\Column(name: 'failure_reason', type: 'text', nullable: true)]
    protected ?string $failureReason = null;

    /**
     * One for the whole shipment, not one per label: it declares what the shipment carries. Null for a shipment
     * that never left its country, and emptied when the file is purged, like the path of a label.
     */
    #[ORM\Column(name: 'customs_document_path', type: 'string', nullable: true)]
    protected ?string $customsDocumentPath = null;

    #[ORM\Column(name: 'customs_document_format', type: 'string', length: 16, nullable: true)]
    protected ?string $customsDocumentFormat = null;

    #[ORM\Column(name: 'customs_document_purged_at', type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $customsDocumentPurgedAt = null;

    /** @var Collection<int, CarrierShipmentLabelInterface> */
    #[ORM\OneToMany(targetEntity: CarrierShipmentLabel::class, mappedBy: 'export', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $labels;

    public function __construct()
    {
        $this->labels = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShipment(): ?ShipmentInterface
    {
        return $this->shipment;
    }

    public function setShipment(?ShipmentInterface $shipment): void
    {
        $this->shipment = $shipment;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function getCarrier(): ?string
    {
        return $this->carrier;
    }

    public function setCarrier(?string $carrier): void
    {
        $this->carrier = $carrier;
    }

    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    public function setEnvironment(?string $environment): void
    {
        $this->environment = $environment;
    }

    public function getCarrierReference(): ?string
    {
        return $this->carrierReference;
    }

    public function setCarrierReference(?string $carrierReference): void
    {
        $this->carrierReference = $carrierReference;
    }

    public function getOwnReference(): ?string
    {
        return $this->ownReference;
    }

    public function setOwnReference(?string $ownReference): void
    {
        $this->ownReference = $ownReference;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(?\DateTimeImmutable $issuedAt): void
    {
        $this->issuedAt = $issuedAt;
    }

    public function getIssuedBy(): ?string
    {
        return $this->issuedBy;
    }

    public function setIssuedBy(?string $issuedBy): void
    {
        $this->issuedBy = $issuedBy;
    }

    public function getVoidedAt(): ?\DateTimeImmutable
    {
        return $this->voidedAt;
    }

    public function setVoidedAt(?\DateTimeImmutable $voidedAt): void
    {
        $this->voidedAt = $voidedAt;
    }

    public function getVoidedBy(): ?string
    {
        return $this->voidedBy;
    }

    public function setVoidedBy(?string $voidedBy): void
    {
        $this->voidedBy = $voidedBy;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): void
    {
        $this->failureReason = $failureReason;
    }

    public function getCustomsDocumentPath(): ?string
    {
        return $this->customsDocumentPath;
    }

    public function setCustomsDocumentPath(?string $customsDocumentPath): void
    {
        $this->customsDocumentPath = $customsDocumentPath;
    }

    public function getCustomsDocumentFormat(): ?string
    {
        return $this->customsDocumentFormat;
    }

    public function setCustomsDocumentFormat(?string $customsDocumentFormat): void
    {
        $this->customsDocumentFormat = $customsDocumentFormat;
    }

    public function getCustomsDocumentPurgedAt(): ?\DateTimeImmutable
    {
        return $this->customsDocumentPurgedAt;
    }

    public function setCustomsDocumentPurgedAt(?\DateTimeImmutable $customsDocumentPurgedAt): void
    {
        $this->customsDocumentPurgedAt = $customsDocumentPurgedAt;
    }

    public function getLabels(): Collection
    {
        return $this->labels;
    }

    public function addLabel(CarrierShipmentLabelInterface $label): void
    {
        if (!$this->labels->contains($label)) {
            $this->labels->add($label);
            $label->setExport($this);
        }
    }

    public function removeLabel(CarrierShipmentLabelInterface $label): void
    {
        if ($this->labels->removeElement($label)) {
            $label->setExport(null);
        }
    }
}
