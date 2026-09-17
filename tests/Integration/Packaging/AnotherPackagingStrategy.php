<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Packaging;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\PackagingStrategyInterface;
use Sylius\Component\Shipping\Model\ShipmentInterface;

/**
 * The packaging strategy of an application that has one of its own, used to prove the plugin's can be replaced
 * from configuration alone.
 */
final class AnotherPackagingStrategy implements PackagingStrategyInterface
{
    public function pack(ShipmentInterface $shipment, CarrierShippingOriginInterface $origin): array
    {
        return [new Package('The box of the application', 1.0, 1.0, 1.0, 'in', 1.0, 'lb', [])];
    }
}
