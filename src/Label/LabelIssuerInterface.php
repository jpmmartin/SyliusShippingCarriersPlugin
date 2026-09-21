<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AlreadyIssuedException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AmbiguousShipmentException;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * Issues the labels of a shipment. Behind an interface so a shop can put its own in front of it, and so that
 * whatever asks — the admin, a batch, a command — asks the same thing.
 */
interface LabelIssuerInterface
{
    /**
     * @param string $issuedBy Who asked for it, as it is shown afterwards
     *
     * @throws \InvalidArgumentException When the shipment is not one of a carrier of this plugin
     * @throws AlreadyIssuedException When the shipment already has its labels
     * @throws AmbiguousShipmentException When nobody knows yet whether a previous attempt was issued
     */
    public function issue(ShipmentInterface $shipment, string $issuedBy): CarrierShipmentExportInterface;

    /**
     * Says that a shipment nobody knew the fate of was never issued, so it can be sent again.
     *
     * @param string $confirmedBy Who looked and said so
     *
     * @throws \InvalidArgumentException When the shipment was not waiting to be checked
     */
    public function confirmNotIssued(CarrierShipmentExportInterface $export, string $confirmedBy): void;
}
