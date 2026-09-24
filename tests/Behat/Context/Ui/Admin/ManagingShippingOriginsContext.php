<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierChannelSettingsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettings;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use Sylius\Behat\Page\Admin\Crud\IndexPageInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\ShippingOrigin\CreatePage;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\ShippingOrigin\UpdatePage;
use Webmozart\Assert\Assert;

final readonly class ManagingShippingOriginsContext implements Context
{
    /**
     * @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository
     * @param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(
        private CreatePage $createPage,
        private IndexPageInterface $indexPage,
        private RepositoryInterface $originRepository,
        private UpdatePage $updatePage,
        private CarrierSettingsProvider $settings,
        private ChannelRepositoryInterface $channelRepository,
        private ObjectManager $originManager,
    ) {
    }

    #[When('I want to modify the shipping origin of the :channelName channel')]
    public function iWantToModifyTheShippingOriginOfTheChannel(string $channelName): void
    {
        $origin = $this->originRepository->findOneBy(['channel' => $this->channel($channelName)]);
        Assert::isInstanceOf($origin, CarrierShippingOriginInterface::class);

        $this->updatePage->open(['id' => $origin->getId()]);
    }

    #[When('I quote its rates for :seconds seconds')]
    public function iQuoteItsRatesForSeconds(string $seconds): void
    {
        $this->updatePage->fillSetting('rate_lifetime', $seconds);
    }

    #[When('I keep the documents of its orders for :seconds seconds')]
    public function iKeepTheDocumentsOfItsOrdersForSeconds(string $seconds): void
    {
        $this->updatePage->fillSetting('documents_retention', $seconds);
    }

    #[When('I keep its rates as the last known rate for :seconds seconds')]
    public function iKeepItsRatesAsTheLastKnownRateForSeconds(string $seconds): void
    {
        $this->updatePage->fillSetting('rate_retention', $seconds);
    }

    #[Then('I should be told that the documents retention cannot be less than :minimum seconds')]
    public function iShouldBeToldThatTheDocumentsRetentionCannotBeLessThanSeconds(string $minimum): void
    {
        Assert::contains($this->updatePage->getValidationMessage('documents_retention'), sprintf('cannot be less than %s seconds', $minimum));
    }

    #[Then('I should be told that a rate cannot be kept for less than the :lifetime seconds it is quoted for')]
    public function iShouldBeToldThatARateCannotBeKeptForLessThanTheSecondsItIsQuotedFor(string $lifetime): void
    {
        Assert::contains($this->updatePage->getValidationMessage('rate_retention'), sprintf('which is %s seconds here', $lifetime));
    }

    #[When('I print its :carrier labels as :format')]
    public function iPrintItsLabelsAs(string $carrier, string $format): void
    {
        $this->updatePage->chooseLabelFormat(strtolower($carrier), $format);
    }

    #[When('I add to it the :carrier service :code named :name')]
    public function iAddToItTheServiceNamed(string $carrier, string $code, string $name): void
    {
        $this->updatePage->fillSetting(strtolower($carrier) . '_services', sprintf('%s = %s', $code, $name));
    }

    #[When('I write its :carrier services as :text')]
    public function iWriteItsServicesAs(string $carrier, string $text): void
    {
        $this->updatePage->fillSetting(strtolower($carrier) . '_services', $text);
    }

    #[When('I save my changes to the shipping origin')]
    public function iSaveMyChangesToTheShippingOrigin(): void
    {
        $this->updatePage->saveChanges();
    }

    #[Then('I should be told that an empty rate lifetime means the configuration\'s :value')]
    public function iShouldBeToldThatAnEmptyRateLifetimeMeansTheConfigurations(string $value): void
    {
        Assert::contains($this->updatePage->getHelp('rate_lifetime'), sprintf('the configuration\'s: %s', $value));
    }

    #[Then('I should be told that an empty :carrier label format means the configuration\'s :format')]
    public function iShouldBeToldThatAnEmptyLabelFormatMeansTheConfigurations(string $carrier, string $format): void
    {
        Assert::contains($this->updatePage->getHelp(strtolower($carrier) . '_label_format'), sprintf('the configuration\'s: %s', $format));
    }

    #[Then('the :channelName channel should quote rates for :seconds seconds')]
    public function theChannelShouldQuoteRatesForSeconds(string $channelName, int $seconds): void
    {
        Assert::same($this->settingsOf($channelName)->rateLifetime, $seconds);
    }

    #[Then('the :channelName channel should keep the status of a shipment for :seconds seconds')]
    public function theChannelShouldKeepTheStatusOfAShipmentForSeconds(string $channelName, int $seconds): void
    {
        Assert::same($this->settingsOf($channelName)->trackingLifetime, $seconds);
    }

    #[Then('the :channelName channel should print :carrier labels as :format')]
    public function theChannelShouldPrintLabelsAs(string $channelName, string $carrier, string $format): void
    {
        Assert::same($this->settingsOf($channelName)->labelFormat(strtolower($carrier)), $format);
    }

    #[Then('the :channelName channel should add the :carrier service :code named :name')]
    public function theChannelShouldAddTheServiceNamed(string $channelName, string $carrier, string $code, string $name): void
    {
        Assert::same($this->savedOrigin($channelName)->getServices(strtolower($carrier)), [$code => $name]);
    }

    #[Then('the shipping origin of the :channelName channel should say nothing in place of the configuration')]
    public function theShippingOriginOfTheChannelShouldSayNothingInPlaceOfTheConfiguration(string $channelName): void
    {
        $origin = $this->savedOrigin($channelName);

        Assert::null($origin->getRateLifetime());
        Assert::null($origin->getDocumentsRetention());
        Assert::null($origin->getLabelFormat('ups'));
        Assert::same($origin->getServices('ups'), []);
    }

    #[Then('I should be told to write each service as :format')]
    public function iShouldBeToldToWriteEachServiceAs(string $format): void
    {
        Assert::contains($this->updatePage->getValidationMessage('ups_services'), $format);
    }

    #[When('I want to add a new shipping origin')]
    public function iWantToAddANewShippingOrigin(): void
    {
        $this->createPage->open();
    }

    #[When('/^the parcels are sent by "([^"]+)", "([^"]+)", on "([^"]+)"$/')]
    public function theParcelsAreSentBy(string $companyName, string $contactName, string $phone): void
    {
        $this->createPage->specifySender($companyName, $contactName, $phone);
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

    #[When('it ships only in the :boxName box')]
    public function itShipsOnlyInTheBox(string $boxName): void
    {
        $this->createPage->restrictToBox($boxName);
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

    #[Then('it should ship only in the :boxName box')]
    public function itShouldShipOnlyInTheBox(string $boxName): void
    {
        $boxes = $this->onlyOrigin()->getBoxes();

        Assert::count($boxes, 1);
        $box = $boxes->first();
        Assert::isInstanceOf($box, CarrierPackageBoxInterface::class);
        Assert::same($box->getName(), $boxName);
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

    /**
     * What the shop uses for the channel now, read afresh: the admin request that saved it ran in another kernel.
     */
    private function settingsOf(string $channelName): CarrierSettings
    {
        $this->savedOrigin($channelName);
        $this->settings->reset();

        return $this->settings->forChannel($this->channel($channelName));
    }

    /**
     * The origin as the admin saved it. Saved by a request served by another kernel, so the one this context runs
     * in still holds it as it was before, until it is read again.
     */
    private function savedOrigin(string $channelName): CarrierChannelSettingsInterface
    {
        $origin = $this->originRepository->findOneBy(['channel' => $this->channel($channelName)]);
        Assert::isInstanceOf($origin, CarrierChannelSettingsInterface::class);
        $this->originManager->refresh($origin);

        return $origin;
    }

    private function channel(string $channelName): ChannelInterface
    {
        $channel = $this->channelRepository->findOneBy(['name' => $channelName]);
        Assert::isInstanceOf($channel, ChannelInterface::class);

        return $channel;
    }

    private function onlyOrigin(): CarrierShippingOriginInterface
    {
        $origins = $this->originRepository->findAll();
        Assert::count($origins, 1);
        Assert::isInstanceOf($origins[0], CarrierShippingOriginInterface::class);

        return $origins[0];
    }
}
