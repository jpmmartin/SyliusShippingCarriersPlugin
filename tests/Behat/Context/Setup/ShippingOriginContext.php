<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierChannelSettingsInterface;
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

        $this->shipFrom($channel, $street, $city, $postcode, $countryName);
    }

    /**
     * A channel with a shipping origin of its own, adding one of the carrier's services to the configuration's.
     */
    #[Given('the :channel channel adds the :carrier service :code named :name on its shipping origin')]
    public function theChannelAddsTheServiceNamedOnItsShippingOrigin(ChannelInterface $channel, string $carrier, string $code, string $name): void
    {
        $origin = $this->originRepository->findOneBy(['channel' => $channel])
            ?? $this->shipFrom($channel, '1 Main St', 'Chicago', '60601', 'United States');
        Assert::isInstanceOf($origin, CarrierChannelSettingsInterface::class);
        Assert::isInstanceOf($origin, CarrierShippingOriginInterface::class);

        $origin->setServices(strtolower($carrier), [$code => $name]);
        $this->originRepository->add($origin);
    }

    private function shipFrom(ChannelInterface $channel, string $street, string $city, string $postcode, string $countryName): CarrierShippingOriginInterface
    {
        $origin = $this->originFactory->createNew();
        $origin->setChannel($channel);
        // Without a sender no label prints, so an origin a scenario sets up has one.
        $origin->setCompanyName('The store');
        $origin->setContactName('Ada Lovelace');
        $origin->setPhone('13057800955');
        $origin->setStreet($street);
        $origin->setCity($city);
        $origin->setPostcode($postcode);
        $origin->setCountryCode($this->countryNameConverter->convertToCode($countryName));
        $origin->setWeightUnit(CarrierShippingOriginInterface::WEIGHT_UNIT_LB);
        $origin->setDimensionUnit(CarrierShippingOriginInterface::DIMENSION_UNIT_IN);
        $origin->setDefaultDestinationType(DestinationType::COMMERCIAL);

        $this->originRepository->add($origin);
        $this->sharedStorage->set('shipping_origin', $origin);

        return $origin;
    }
}
