<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;

/**
 * Where a shipment of a carrier of this plugin stands: what has been asked of the carrier, and therefore what
 * the admin may offer to do next.
 */
final readonly class ShipmentLabels
{
    /**
     * @param string $carrier The carrier's code, such as `ups`
     * @param CarrierShipmentExportInterface|null $export Null when nothing has been asked of the carrier yet
     */
    public function __construct(
        public string $carrier,
        public ?CarrierShipmentExportInterface $export = null,
    ) {
    }

    public function isIssued(): bool
    {
        return CarrierShipmentExportInterface::STATE_ISSUED === $this->export?->getState();
    }

    /**
     * Nobody knows whether the carrier issued it, so it is not offered again until somebody says it did not.
     */
    public function needsCheck(): bool
    {
        return CarrierShipmentExportInterface::STATE_NEEDS_CHECK === $this->export?->getState();
    }

    /**
     * The labels of a shipment that has none, that never got them, or whose own were cancelled.
     */
    public function canBeIssued(): bool
    {
        return !$this->isIssued() && !$this->needsCheck();
    }

    public function failureReason(): ?string
    {
        return $this->export?->getFailureReason();
    }
}
