<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** @internal */
final class ShipmentTrackingExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('jpmmartin_carrier_tracked_shipments', [ShipmentTrackingRuntime::class, 'ofOrder']),
        ];
    }
}
