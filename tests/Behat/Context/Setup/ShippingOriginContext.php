<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Addressing\Converter\CountryNameConverterInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

final readonly class ShippingOriginContext implements Context
{
    /**
     * @param FactoryInterface<CarrierShippingOriginInterface> $originFactory
     * @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository
     */
    public function __construct(
        private SharedStorageInterface $sharedStorage,
        private FactoryInterface $originFactory,
        private RepositoryInterface $originRepository,
        private CountryNameConverterInterface $countryNameConverter,
    ) {
    }

    /**
     * Its catalog is in pounds and inches, and a buyer who does not say otherwise ships to a business.
     */
    #[Given('/^the store ships from "([^"]+)", "([^"]+)" "([^"]+)" in the "([^"]+)"$/')]
    public function theStoreShipsFrom(string $street, string $city, string $postcode, string $countryName): void
    {
        $channel = $this->sharedStorage->get('channel');
        Assert::isInstanceOf($channel, ChannelInterface::class);

        $origin = $this->originFactory->createNew();
        $origin->setChannel($channel);
        $origin->setStreet($street);
        $origin->setCity($city);
        $origin->setPostcode($postcode);
        $origin->setCountryCode($this->countryNameConverter->convertToCode($countryName));
        $origin->setWeightUnit(CarrierShippingOriginInterface::WEIGHT_UNIT_LB);
        $origin->setDimensionUnit(CarrierShippingOriginInterface::DIMENSION_UNIT_IN);
        $origin->setDefaultDestinationType(DestinationType::COMMERCIAL);

        $this->originRepository->add($origin);
        $this->sharedStorage->set('shipping_origin', $origin);
    }
}
