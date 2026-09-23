<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Customs;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackageInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;

/**
 * What a package is worth for customs: the sum of what was paid for the units inside it.
 *
 * What was paid, not what the catalogue asks. A parcel declared at the list price of goods that were sold at
 * half of it is a declaration that does not match the invoice, which is what customs compares it against.
 *
 * @internal
 */
final readonly class DeclaredValueCalculator
{
    /**
     * @return int In hundredths, as Sylius keeps every amount
     */
    public function forPackage(CarrierShipmentPackageInterface $package): int
    {
        $value = 0;
        foreach ($package->getUnits() as $unit) {
            if ($unit instanceof OrderItemUnitInterface) {
                $value += $unit->getTotal();
            }
        }

        return $value;
    }
}
