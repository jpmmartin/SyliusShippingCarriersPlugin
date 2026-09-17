<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Behat\Page\Admin\Crud\IndexPageInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\ShippingOrigin\CreatePage;
use Webmozart\Assert\Assert;

final readonly class ManagingShippingOriginsContext implements Context
{
    /**
     * @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository
     */
    public function __construct(
        private CreatePage $createPage,
        private IndexPageInterface $indexPage,
        private RepositoryInterface $originRepository,
    ) {
    }

    #[When('I want to add a new shipping origin')]
    public function iWantToAddANewShippingOrigin(): void
    {
        $this->createPage->open();
    }

    #[When('/^I ship from "([^"]+)", "([^"]+)" "([^"]+)" in the "([^"]+)"$/')]
    public function iShipFrom(string $street, string $city, string $postcode, string $countryName): void
    {
        $this->createPage->specifyAddress($street, $city, $postcode, $countryName);
    }

    #[When('I ship the orders of the :channelName channel')]
    public function iShipTheOrdersOfTheChannel(string $channelName): void
    {
        $this->createPage->chooseChannel($channelName);
    }

    #[When('I declare the catalog in :weightUnit and :dimensionUnit')]
    public function iDeclareTheCatalogIn(string $weightUnit, string $dimensionUnit): void
    {
        $this->createPage->chooseUnits($weightUnit, $dimensionUnit);
    }

    #[When('I deliver to a :destinationType unless the buyer says otherwise')]
    public function iDeliverToUnlessTheBuyerSaysOtherwise(string $destinationType): void
    {
        $this->createPage->chooseDefaultDestinationType(ucfirst($destinationType));
    }

    #[When('I add it')]
    public function iAddIt(): void
    {
        $this->createPage->create();
    }

    #[Then('/^the "([^"]+)" channel should ship from "([^"]+)", "([^"]+)" "([^"]+)" in (?:the )?"([^"]+)"$/')]
    public function theChannelShouldShipFrom(string $channelName, string $street, string $city, string $postcode, string $countryCode): void
    {
        $this->indexPage->open();
        Assert::true($this->indexPage->isSingleResourceOnPage(['channel' => $channelName, 'city' => $city]));

        $origin = $this->originRepository->findOneBy(['city' => $city]);
        Assert::isInstanceOf($origin, CarrierShippingOriginInterface::class);
        Assert::same($origin->getStreet(), $street);
        Assert::same($origin->getPostcode(), $postcode);
        Assert::same($origin->getCountryCode(), $countryCode);
    }

    #[Then('its catalog should be in :weightUnit and :dimensionUnit')]
    public function itsCatalogShouldBeIn(string $weightUnit, string $dimensionUnit): void
    {
        $origin = $this->onlyOrigin();

        Assert::same($origin->getWeightUnit(), $weightUnit);
        Assert::same($origin->getDimensionUnit(), $dimensionUnit);
    }

    #[Then('it should deliver to a :destinationType unless the buyer says otherwise')]
    public function itShouldDeliverToUnlessTheBuyerSaysOtherwise(string $destinationType): void
    {
        Assert::same($this->onlyOrigin()->getDefaultDestinationType(), $destinationType);
    }

    #[Then('I should be told that the channel already ships from somewhere')]
    public function iShouldBeToldThatTheChannelAlreadyShipsFromSomewhere(): void
    {
        Assert::contains($this->createPage->getValidationMessage('channel'), 'already has a shipping origin');
    }

    #[Then('I should be told to say where deliveries go by default')]
    public function iShouldBeToldToSayWhereDeliveriesGoByDefault(): void
    {
        Assert::contains($this->createPage->getValidationMessage('default_destination_type'), 'Choose whether deliveries go to homes or businesses');
    }

    #[Then('the store should still ship from nowhere')]
    public function theStoreShouldStillShipFromNowhere(): void
    {
        Assert::count($this->originRepository->findAll(), 0);
    }

    #[Then('there should be only one shipping origin')]
    public function thereShouldBeOnlyOneShippingOrigin(): void
    {
        Assert::count($this->originRepository->findAll(), 1);
    }

    private function onlyOrigin(): CarrierShippingOriginInterface
    {
        $origins = $this->originRepository->findAll();
        Assert::count($origins, 1);
        Assert::isInstanceOf($origins[0], CarrierShippingOriginInterface::class);

        return $origins[0];
    }
}
