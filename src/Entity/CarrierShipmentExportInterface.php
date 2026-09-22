<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\Common\Collections\Collection;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * What happened when a shipment was handed to its carrier.
 */
interface CarrierShipmentExportInterface extends ResourceInterface
{
    /** Nothing has been asked of the carrier yet. */
    public const STATE_PENDING = 'pending';

    /** The carrier issued the labels and they are stored. */
    public const STATE_ISSUED = 'issued';

    /** The carrier refused, and said why. */
    public const STATE_FAILED = 'failed';

    /**
     * Nobody knows whether the carrier issued it: the request left and no usable answer came back. A state of
     * its own, not a failure with nuances, because acting on it as a failure is how a shipment gets paid for
     * twice.
     */
    public const STATE_NEEDS_CHECK = 'needs_check';

    /** It was issued and then cancelled with the carrier. */
    public const STATE_VOIDED = 'voided';

    /** @var list<self::STATE_*> */
    public const STATES = [
        self::STATE_PENDING,
        self::STATE_ISSUED,
        self::STATE_FAILED,
        self::STATE_NEEDS_CHECK,
        self::STATE_VOIDED,
    ];

    public function getShipment(): ?ShipmentInterface;

    public function setShipment(?ShipmentInterface $shipment): void;

    /** @return self::STATE_* */
    public function getState(): string;

    /** @param self::STATE_* $state */
    public function setState(string $state): void;

    public function getCarrier(): ?string;

    public function setCarrier(?string $carrier): void;

    public function getEnvironment(): ?string;

    public function setEnvironment(?string $environment): void;

    /** What the carrier calls this shipment. Without it nothing can be cancelled. */
    public function getCarrierReference(): ?string;

    public function setCarrierReference(?string $carrierReference): void;

    /**
     * What the plugin called the last attempt, and sent the carrier as its own reference. It is what the
     * carrier is asked about when it gave no answer.
     */
    public function getOwnReference(): ?string;

    public function setOwnReference(?string $ownReference): void;

    public function getIssuedAt(): ?\DateTimeImmutable;

    public function setIssuedAt(?\DateTimeImmutable $issuedAt): void;

    public function getIssuedBy(): ?string;

    public function setIssuedBy(?string $issuedBy): void;

    public function getVoidedAt(): ?\DateTimeImmutable;

    public function setVoidedAt(?\DateTimeImmutable $voidedAt): void;

    public function getVoidedBy(): ?string;

    public function setVoidedBy(?string $voidedBy): void;

    public function getFailureReason(): ?string;

    public function setFailureReason(?string $failureReason): void;

    /** Where the customs document of the shipment is kept. Null when it has none, or no longer has it. */
    public function getCustomsDocumentPath(): ?string;

    public function setCustomsDocumentPath(?string $customsDocumentPath): void;

    public function getCustomsDocumentFormat(): ?string;

    public function setCustomsDocumentFormat(?string $customsDocumentFormat): void;

    public function getCustomsDocumentPurgedAt(): ?\DateTimeImmutable;

    public function setCustomsDocumentPurgedAt(?\DateTimeImmutable $customsDocumentPurgedAt): void;

    /** @return Collection<int, CarrierShipmentLabelInterface> One per package. */
    public function getLabels(): Collection;

    public function addLabel(CarrierShipmentLabelInterface $label): void;

    public function removeLabel(CarrierShipmentLabelInterface $label): void;
}
