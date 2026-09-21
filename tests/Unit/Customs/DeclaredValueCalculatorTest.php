<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Customs;

use JpmMartin\SyliusShippingCarriersPlugin\Customs\DeclaredValueCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackage;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;

/**
 * What a parcel is declared to be worth. Customs compares the declaration against the invoice, so it is what
 * the buyer paid and not what the catalogue asks.
 */
final class DeclaredValueCalculatorTest extends TestCase
{
    public function testAPackageIsWorthWhatWasPaidForWhatIsInside(): void
    {
        $package = $this->package([$this->unit(1200), $this->unit(1200), $this->unit(2500)]);

        self::assertSame(4900, (new DeclaredValueCalculator())->forPackage($package));
    }

    /**
     * The test the criterion asks for: goods sold at half price are declared at half price.
     */
    public function testADiscountIsAlreadyTakenOffWhatIsDeclared(): void
    {
        $discounted = $this->unit(2000);
        $discounted->addAdjustment($this->discount(-500));

        self::assertSame(1500, (new DeclaredValueCalculator())->forPackage($this->package([$discounted])));
    }

    public function testAPackageWithNothingInItIsWorthNothing(): void
    {
        self::assertSame(0, (new DeclaredValueCalculator())->forPackage($this->package([])));
    }

    /**
     * @param list<OrderItemUnit> $units
     */
    private function package(array $units): CarrierShipmentPackage
    {
        $package = new CarrierShipmentPackage();
        foreach ($units as $unit) {
            $package->addUnit($unit);
        }

        return $package;
    }

    private function unit(int $unitPrice): OrderItemUnit
    {
        $item = new OrderItem();
        $item->setUnitPrice($unitPrice);

        return new OrderItemUnit($item);
    }

    private function discount(int $amount): AdjustmentInterface
    {
        $adjustment = new Adjustment();
        $adjustment->setType(AdjustmentInterface::ORDER_UNIT_PROMOTION_ADJUSTMENT);
        $adjustment->setAmount($amount);

        return $adjustment;
    }
}
