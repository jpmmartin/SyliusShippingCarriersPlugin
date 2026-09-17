<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\PackagingStrategyInterface;
use Sylius\Component\Shipping\Model\ShipmentInterface;

/**
 * Packs everything into one package of its own, and counts how often it is asked. Standing in for the strategy of
 * the store, it shows whether anything packs a shipment again.
 */
final class RecordingPackagingStrategy implements PackagingStrategyInterface
{
    public int $calls = 0;

    public function pack(ShipmentInterface $shipment, CarrierShippingOriginInterface $origin): array
    {
        ++$this->calls;

        return [new Package('Another box', 99.0, 99.0, 99.0, 'in', 99.0, 'lb', [])];
    }
}
