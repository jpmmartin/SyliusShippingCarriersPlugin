<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Customs;

use JpmMartin\SyliusShippingCarriersPlugin\Customs\CustomsDataProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Customs\Exception\MissingCustomsDataException;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsData;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsDataInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackage;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * What customs is told a parcel holds. A declaration that cannot be filled in is better caught in the
 * warehouse than at the airport, so a variant that cannot be declared stops the shipment by name.
 */
final class CustomsDataProviderTest extends TestCase
{
    /** @var array<string, CarrierCustomsDataInterface> By variant code */
    private array $customsData = [];

    protected function setUp(): void
    {
        $this->customsData = [];
    }

    public function testEachVariantOfThePackageIsDeclaredWithItsCodeAndOrigin(): void
    {
        $mug = $this->variant('MUG', 'Mug');
        $cap = $this->variant('CAP', 'Cap');
        $this->declare('MUG', '691200', 'PT');
        $this->declare('CAP', '650590', 'ES');

        $package = $this->package([$this->unit($mug, 1200), $this->unit($mug, 1200), $this->unit($cap, 2500)]);

        $items = $this->provider()->forPackage($package, 'EUR');

        self::assertCount(2, $items);
        self::assertSame(['691200', 'PT', 'Mug', 2, 1200, 'EUR', 'MUG'], [
            $items[0]->hsCode, $items[0]->countryOfOrigin, $items[0]->description,
            $items[0]->quantity, $items[0]->unitValue, $items[0]->currencyCode, $items[0]->code,
        ]);
        self::assertSame(['650590', 'ES', 'Cap', 1, 2500, 'CAP'], [
            $items[1]->hsCode, $items[1]->countryOfOrigin, $items[1]->description,
            $items[1]->quantity, $items[1]->unitValue, $items[1]->code,
        ]);
    }

    /**
     * What customs is told is what the buyer paid, discounts and all, not what the catalogue says.
     */
    public function testWhatIsDeclaredIsWhatWasPaidAndNotTheCataloguePrice(): void
    {
        $mug = $this->variant('MUG', 'Mug');
        $this->declare('MUG', '691200', 'PT');

        $unit = $this->unit($mug, 2000);
        $unit->addAdjustment($this->discount(-500));

        $items = $this->provider()->forPackage($this->package([$unit]), 'EUR');

        self::assertCount(1, $items);
        self::assertSame(1500, $items[0]->unitValue);
    }

    /**
     * The same variant bought at two prices is two lines, because that is what was paid for each of them.
     */
    public function testTheSameVariantAtTwoPricesIsDeclaredTwice(): void
    {
        $mug = $this->variant('MUG', 'Mug');
        $this->declare('MUG', '691200', 'PT');

        $discounted = $this->unit($mug, 2000);
        $discounted->addAdjustment($this->discount(-500));

        $items = $this->provider()->forPackage($this->package([$this->unit($mug, 2000), $discounted]), 'EUR');

        self::assertCount(2, $items);
        self::assertSame([2000, 1500], [$items[0]->unitValue, $items[1]->unitValue]);
        self::assertSame([1, 1], [$items[0]->quantity, $items[1]->quantity]);
    }

    /**
     * The one that matters: «something is wrong with the customs data» leaves whoever reads it a catalogue to
     * search through.
     */
    public function testAVariantWithNoCustomsDataAtAllIsNamed(): void
    {
        $package = $this->package([$this->unit($this->variant('MUG', 'Mug'), 1200)]);

        try {
            $this->provider()->forPackage($package, 'EUR');
            self::fail('A variant that cannot be declared has to stop the shipment.');
        } catch (MissingCustomsDataException $exception) {
            self::assertStringContainsString('MUG', $exception->getMessage());
            self::assertStringContainsString('HS code', $exception->getMessage());
            self::assertStringContainsString('country of origin', $exception->getMessage());
        }
    }

    public function testAVariantMissingOnlyItsHsCodeSaysSo(): void
    {
        $this->declare('MUG', null, 'PT');
        $package = $this->package([$this->unit($this->variant('MUG', 'Mug'), 1200)]);

        try {
            $this->provider()->forPackage($package, 'EUR');
            self::fail('A variant with no HS code has to stop the shipment.');
        } catch (MissingCustomsDataException $exception) {
            self::assertSame('The variant "MUG" is missing an HS code.', $exception->getMessage());
        }
    }

    public function testAVariantMissingOnlyItsCountryOfOriginSaysSo(): void
    {
        $this->declare('MUG', '691200', null);
        $package = $this->package([$this->unit($this->variant('MUG', 'Mug'), 1200)]);

        try {
            $this->provider()->forPackage($package, 'EUR');
            self::fail('A variant with no country of origin has to stop the shipment.');
        } catch (MissingCustomsDataException $exception) {
            self::assertSame('The variant "MUG" is missing a country of origin.', $exception->getMessage());
        }
    }

    /**
     * Customs reads the description, so a variant with no name of its own borrows the product's.
     */
    public function testAVariantWithoutItsOwnNameIsDescribedByItsProduct(): void
    {
        $variant = $this->variant('MUG', null, 'Enamel mug');
        $this->declare('MUG', '691200', 'PT');

        $items = $this->provider()->forPackage($this->package([$this->unit($variant, 1200)]), 'EUR');

        self::assertSame('Enamel mug', $items[0]->description);
    }

    public function testAPackageWithNothingInItDeclaresNothing(): void
    {
        self::assertSame([], $this->provider()->forPackage($this->package([]), 'EUR'));
    }

    private function provider(): CustomsDataProvider
    {
        /** @var RepositoryInterface<CarrierCustomsDataInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria): ?CarrierCustomsDataInterface {
            $variant = $criteria['variant'] ?? null;

            return $variant instanceof ProductVariantInterface ? ($this->customsData[(string) $variant->getCode()] ?? null) : null;
        });

        return new CustomsDataProvider($repository);
    }

    private function declare(string $variantCode, ?string $hsCode, ?string $countryOfOrigin): void
    {
        $customsData = new CarrierCustomsData();
        $customsData->setHsCode($hsCode);
        $customsData->setCountryOfOrigin($countryOfOrigin);

        $this->customsData[$variantCode] = $customsData;
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

    private function unit(ProductVariantInterface $variant, int $unitPrice): OrderItemUnit
    {
        $item = new OrderItem();
        $item->setVariant($variant);
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

    private function variant(string $code, ?string $name, ?string $productName = null): ProductVariant
    {
        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setCode($code . '_PRODUCT');
        $product->setName($productName ?? $code);

        $variant = new ProductVariant();
        $variant->setCurrentLocale('en_US');
        $variant->setFallbackLocale('en_US');
        $variant->setCode($code);
        $variant->setName($name);
        $variant->setProduct($product);

        return $variant;
    }
}
