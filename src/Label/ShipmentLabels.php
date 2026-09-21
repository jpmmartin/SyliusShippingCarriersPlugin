<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;

/**
 * Where a shipment of a carrier of this plugin stands: what has been asked of the carrier, and therefore what
 * the admin may offer to do next.
 */
final readonly class ShipmentLabels
{
    /**
     * @param string $carrier The carrier's code, such as `ups`
     * @param CarrierShipmentExportInterface|null $export Null when nothing has been asked of the carrier yet
     * @param VoidWindowState|null $voidWindow How long is left to cancel it, when there are labels to cancel
     */
    public function __construct(
        public string $carrier,
        public ?CarrierShipmentExportInterface $export = null,
        public ?VoidWindowState $voidWindow = null,
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

    /**
     * The labels there is still a file for. A cancelled shipment has none to offer: what the carrier no longer
     * recognises must not be handed to a warehouse.
     *
     * @return list<CarrierShipmentLabelInterface>
     */
    public function downloadable(): array
    {
        if (!$this->isIssued()) {
            return [];
        }

        return array_values(array_filter(
            $this->export?->getLabels()->toArray() ?? [],
            static fn (CarrierShipmentLabelInterface $label): bool => !$label->isPurged() && null !== $label->getPath(),
        ));
    }

    public function failureReason(): ?string
    {
        return $this->export?->getFailureReason();
    }
}
