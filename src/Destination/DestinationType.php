<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Destination;

/**
 * Whether a destination is a home or a business, which changes the price of a delivery.
 */
final class DestinationType
{
    public const RESIDENTIAL = 'residential';

    public const COMMERCIAL = 'commercial';

    private function __construct()
    {
    }
}
