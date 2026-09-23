<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Twig;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\ShipmentLabels;
use JpmMartin\SyliusShippingCarriersPlugin\Label\VoidWindow;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShipmentCarrier;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * What the admin may offer to do with the labels of a shipment.
 *
 * @internal
 */
final readonly class ShipmentLabelsRuntime implements RuntimeExtensionInterface
{
    /**
     * @param RepositoryInterface<CarrierShipmentExportInterface> $exportRepository
     */
    public function __construct(
        private ShipmentCarrier $shipmentCarrier,
        private RepositoryInterface $exportRepository,
        private VoidWindow $voidWindow,
    ) {
    }

    /**
     * Null when the shipment is not sent by a carrier of this plugin, which is how the admin knows to offer
     * nothing at all for it.
     */
    public function of(ShipmentInterface $shipment): ?ShipmentLabels
    {
        $carrier = $this->shipmentCarrier->of($shipment);
        if (null === $carrier) {
            return null;
        }

        $export = $this->exportRepository->findOneBy(['shipment' => $shipment]);
        $export = $export instanceof CarrierShipmentExportInterface ? $export : null;
        $issuedAt = CarrierShipmentExportInterface::STATE_ISSUED === $export?->getState() ? $export->getIssuedAt() : null;

        return new ShipmentLabels(
            $carrier,
            $export,
            null === $issuedAt ? null : $this->voidWindow->of($carrier, $issuedAt),
        );
    }
}
