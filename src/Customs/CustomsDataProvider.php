<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Customs;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsItem;
use JpmMartin\SyliusShippingCarriersPlugin\Customs\Exception\MissingCustomsDataException;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsDataInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackageInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * What customs is told a package contains, read from the units that were stored in it.
 *
 * A variant with no HS code or no country of origin stops the shipment, and the message names the variant and
 * the field: a customs declaration that is wrong is held at the border, and one that cannot be filled in is
 * better caught in the warehouse than at the airport.
 */
final readonly class CustomsDataProvider
{
    /**
     * @param RepositoryInterface<CarrierCustomsDataInterface> $customsDataRepository
     */
    public function __construct(
        private RepositoryInterface $customsDataRepository,
    ) {
    }

    /**
     * One line per variant and price paid: the same variant bought at two prices is two lines, because that is
     * what was paid for each of them.
     *
     * @param string $currencyCode What the order was paid in
     *
     * @return list<CustomsItem>
     *
     * @throws MissingCustomsDataException When a variant cannot be declared
     */
    public function forPackage(CarrierShipmentPackageInterface $package, string $currencyCode): array
    {
        /** @var array<string, array{ProductVariantInterface, int, int}> $lines variant, unit value, quantity */
        $lines = [];

        foreach ($package->getUnits() as $unit) {
            if (!$unit instanceof OrderItemUnitInterface) {
                continue;
            }

            $orderItem = $unit->getOrderItem();
            $variant = $orderItem instanceof OrderItemInterface ? $orderItem->getVariant() : null;
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }

            // What was actually paid for this unit, with its discounts already taken off (002 declares this).
            $unitValue = $unit->getTotal();
            $key = sprintf('%s|%d', (string) $variant->getId(), $unitValue);

            $lines[$key] ??= [$variant, $unitValue, 0];
            ++$lines[$key][2];
        }

        $items = [];
        foreach ($lines as [$variant, $unitValue, $quantity]) {
            $customsData = $this->customsDataOf($variant);

            $items[] = new CustomsItem(
                (string) $customsData->getHsCode(),
                (string) $customsData->getCountryOfOrigin(),
                self::description($variant),
                $quantity,
                $unitValue,
                $currencyCode,
                (string) $variant->getCode(),
            );
        }

        return $items;
    }

    /**
     * @throws MissingCustomsDataException
     */
    private function customsDataOf(ProductVariantInterface $variant): CarrierCustomsDataInterface
    {
        $customsData = $this->customsDataRepository->findOneBy(['variant' => $variant]);
        if (!$customsData instanceof CarrierCustomsDataInterface) {
            throw new MissingCustomsDataException(sprintf(
                'The variant "%s" has no customs data: it needs an HS code and a country of origin.',
                self::name($variant),
            ));
        }

        $missing = [];
        if (null === $customsData->getHsCode() || '' === $customsData->getHsCode()) {
            $missing[] = 'an HS code';
        }

        if (null === $customsData->getCountryOfOrigin() || '' === $customsData->getCountryOfOrigin()) {
            $missing[] = 'a country of origin';
        }

        if ([] !== $missing) {
            throw new MissingCustomsDataException(sprintf(
                'The variant "%s" is missing %s.',
                self::name($variant),
                implode(' and ', $missing),
            ));
        }

        return $customsData;
    }

    /**
     * What goes on the declaration. Customs reads it, so it is the name of the thing and not its code.
     */
    private static function description(ProductVariantInterface $variant): string
    {
        $name = trim((string) $variant->getName());
        if ('' !== $name) {
            return $name;
        }

        $productName = trim((string) $variant->getProduct()?->getName());

        return '' === $productName ? self::name($variant) : $productName;
    }

    /**
     * How the variant is named to whoever has to go and fix it: its code, which is what the admin searches by.
     */
    private static function name(ProductVariantInterface $variant): string
    {
        return (string) $variant->getCode();
    }
}
