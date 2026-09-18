<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;

/**
 * A package as it goes to the carrier: what it physically is, and what it is worth.
 *
 * The package itself is the one that was stored when the order was confirmed. Nothing recalculates it.
 */
final readonly class ShipmentPackage
{
    /**
     * @param int|null $declaredValue In hundredths, as Sylius keeps every amount. Null when nothing crosses a
     *                                border and customs asks nothing
     * @param list<CustomsItem> $customsItems Empty for a domestic shipment
     */
    public function __construct(
        public Package $package,
        public ?int $declaredValue = null,
        public ?string $declaredValueCurrency = null,
        public array $customsItems = [],
    ) {
    }
}
