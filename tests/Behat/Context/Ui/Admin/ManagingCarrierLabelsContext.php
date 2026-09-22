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
}
