<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsDataInterface;
use Sylius\Component\Addressing\Converter\CountryNameConverterInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * What customs has to be told about an article before it can cross a border.
 *
 * A product a scenario says nothing about is the interesting case, not an oversight: that is what a catalogue
 * looks like before anybody fills it in, and it is what has to stop an international shipment.
 */
final readonly class CustomsContext implements Context
{
    /**
     * @param FactoryInterface<CarrierCustomsDataInterface> $customsDataFactory
     * @param RepositoryInterface<CarrierCustomsDataInterface> $customsDataRepository
     */
    public function __construct(
        private FactoryInterface $customsDataFactory,
        private RepositoryInterface $customsDataRepository,
        private CountryNameConverterInterface $countryNameConverter,
    ) {
    }

    #[Given('/^(product "[^"]+") is declared as "([^"]+)" made in the "([^"]+)"$/')]
    public function theProductIsDeclaredAsMadeIn(ProductInterface $product, string $hsCode, string $countryName): void
    {
        $customsData = $this->customsDataFactory->createNew();
        $customsData->setVariant(self::onlyVariantOf($product));
        $customsData->setHsCode($hsCode);
        $customsData->setCountryOfOrigin($this->countryNameConverter->convertToCode($countryName));

        $this->customsDataRepository->add($customsData);
    }

    /**
     * Half-filled in, which is as useless at a border as not filled in at all.
     */
    #[Given('/^(product "[^"]+") is declared as "([^"]+)" with nowhere it was made$/')]
    public function theProductIsDeclaredWithNowhereItWasMade(ProductInterface $product, string $hsCode): void
    {
        $customsData = $this->customsDataFactory->createNew();
        $customsData->setVariant(self::onlyVariantOf($product));
        $customsData->setHsCode($hsCode);

        $this->customsDataRepository->add($customsData);
    }

    private static function onlyVariantOf(ProductInterface $product): ProductVariantInterface
    {
        $variant = $product->getVariants()->first();
        Assert::isInstanceOf($variant, ProductVariantInterface::class);

        return $variant;
    }
}
