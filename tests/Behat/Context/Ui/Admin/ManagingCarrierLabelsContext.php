<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Component\Core\Model\OrderInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\Order\ShowPage;
use Webmozart\Assert\Assert;

/**
 * What an operator does with the labels of a shipment from the order it belongs to, which is the whole point:
 * never having to open the carrier's own website to send a parcel.
 */
final readonly class ManagingCarrierLabelsContext implements Context
{
    public function __construct(
        private ShowPage $orderShowPage,
        private FakeCarrierState $fakeCarrierState,
    ) {
    }

    #[When('I want to ship the order :order')]
    public function iWantToShipTheOrder(OrderInterface $order): void
    {
        $this->orderShowPage->open(['id' => $order->getId()]);
    }

    #[When('I issue the labels of its shipment')]
    public function iIssueTheLabelsOfItsShipment(): void
    {
        $this->orderShowPage->issueTheLabels();
    }

    #[Then('/^its shipment should have (\d+) labels? to download$/')]
    public function itsShipmentShouldHaveLabelsToDownload(int $count): void
    {
        Assert::same($this->orderShowPage->countLabelsToDownload(), $count);
    }

    #[Then('I should not be offered to issue them again')]
    public function iShouldNotBeOfferedToIssueThemAgain(): void
    {
        Assert::false($this->orderShowPage->isIssuingOffered());
    }

    #[Then('/^(UPS|FedEx) should have been asked to issue them once$/')]
    public function theCarrierShouldHaveBeenAskedToIssueThemOnce(string $carrierName): void
    {
        Assert::same($this->fakeCarrierState->shipCalls(strtolower($carrierName)), 1);
    }

    /**
     * A shipment the catalogue cannot declare is stopped before the carrier is asked anything: nothing is
     * bought and nothing is billed.
     */
    #[Then('/^(UPS|FedEx) should not have been asked to issue anything$/')]
    public function theCarrierShouldNotHaveBeenAskedToIssueAnything(string $carrierName): void
    {
        Assert::same($this->fakeCarrierState->shipCalls(strtolower($carrierName)), 0);
    }

    #[When('I cancel the labels of its shipment')]
    public function iCancelTheLabelsOfItsShipment(): void
    {
        $this->orderShowPage->cancelTheLabels();
    }

    #[Then('/^(UPS|FedEx) should have been told to cancel "([^"]+)"$/')]
    public function theCarrierShouldHaveBeenToldToCancel(string $carrierName, string $reference): void
    {
        Assert::inArray($reference, $this->fakeCarrierState->voided(strtolower($carrierName)));
    }

    #[Then('/^(UPS|FedEx) should not have been told to cancel anything$/')]
    public function theCarrierShouldNotHaveBeenToldToCancelAnything(string $carrierName): void
    {
        Assert::isEmpty($this->fakeCarrierState->voided(strtolower($carrierName)));
    }

    #[Then('I should be offered to issue them again')]
    public function iShouldBeOfferedToIssueThemAgain(): void
    {
        Assert::true($this->orderShowPage->isIssuingOffered());
    }

    #[Then('I should not be offered to cancel them again')]
    public function iShouldNotBeOfferedToCancelThemAgain(): void
    {
        Assert::false($this->orderShowPage->isCancellingOffered());
    }

    #[Then('I should still be offered to cancel them')]
    public function iShouldStillBeOfferedToCancelThem(): void
    {
        Assert::true($this->orderShowPage->isCancellingOffered());
    }

    #[Then('/^the screen should keep saying that the carrier would not cancel because "([^"]+)"$/')]
    public function theScreenShouldKeepSayingWhyTheCarrierWouldNotCancel(string $reason): void
    {
        Assert::same($this->orderShowPage->whyTheCarrierWouldNotCancel(), $reason);
    }

    #[Then('its shipment should have its customs document to download')]
    public function itsShipmentShouldHaveItsCustomsDocumentToDownload(): void
    {
        Assert::true($this->orderShowPage->hasCustomsDocument());
    }

    #[Then('its shipment should have no customs document to download')]
    public function itsShipmentShouldHaveNoCustomsDocumentToDownload(): void
    {
        Assert::false($this->orderShowPage->hasCustomsDocument());
    }
}
