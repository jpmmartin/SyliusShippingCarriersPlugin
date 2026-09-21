<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * What happened to one shipment of a batch. Every shipment gets one, whatever happened to the others.
 */
final readonly class BatchResult
{
    /**
     * @param CarrierShipmentExportInterface|null $export What was recorded, or null when the shipment was
     *                                                    never handed over at all
     * @param string|null $refusal Why it was not even attempted
     */
    private function __construct(
        public ShipmentInterface $shipment,
        public ?CarrierShipmentExportInterface $export = null,
        public ?string $refusal = null,
    ) {
    }

    public static function of(ShipmentInterface $shipment, CarrierShipmentExportInterface $export): self
    {
        return new self($shipment, $export);
    }

    /**
     * The shipment was in no state to be issued, so nothing was asked of the carrier.
     */
    public static function refused(ShipmentInterface $shipment, string $reason): self
    {
        return new self($shipment, null, $reason);
    }

    public function wasIssued(): bool
    {
        return CarrierShipmentExportInterface::STATE_ISSUED === $this->export?->getState();
    }

    /**
     * Nobody knows whether the carrier issued it, which is neither a success nor a failure to retry.
     */
    public function needsCheck(): bool
    {
        return CarrierShipmentExportInterface::STATE_NEEDS_CHECK === $this->export?->getState();
    }

    /**
     * Null when it was issued: there is nothing to explain.
     */
    public function reason(): ?string
    {
        if ($this->wasIssued()) {
            return null;
        }

        return $this->refusal ?? $this->export?->getFailureReason();
    }
}
