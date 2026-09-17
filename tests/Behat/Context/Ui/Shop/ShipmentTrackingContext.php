<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use Behat\Step\Then;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;
use Webmozart\Assert\Assert;

/**
 * What the buyer is told, on the order they opened in their account, about where its shipments are.
 */
final readonly class ShipmentTrackingContext implements Context
{
    public function __construct(
        private Session $session,
        private FakeCarrierState $fakeCarrierState,
    ) {
    }

    #[Then('/^I should see the tracking number "([^"]+)"$/')]
    public function iShouldSeeTheTrackingNumber(string $trackingNumber): void
    {
        Assert::contains($this->tracking()->getText(), $trackingNumber);
    }

    #[Then('/^I should see that my shipment is "([^"]+)"$/')]
    public function iShouldSeeThatMyShipmentIs(string $status): void
    {
        $element = $this->tracking()->find('css', '[data-test-carrier-tracking-status]');
        Assert::isInstanceOf($element, NodeElement::class, 'No status of the shipment is shown.');
        Assert::same(trim($element->getText()), $status);
    }

    #[Then('/^I should see that "([^"]+)" happened$/')]
    public function iShouldSeeThatHappened(string $description): void
    {
        $element = $this->tracking()->find('css', '[data-test-carrier-tracking-events]');
        Assert::isInstanceOf($element, NodeElement::class, 'No event of the shipment is shown.');
        Assert::contains($element->getText(), $description);
    }

    #[Then('I should be told that the status of my shipment is not available')]
    public function iShouldBeToldThatTheStatusOfMyShipmentIsNotAvailable(): void
    {
        Assert::notNull(
            $this->tracking()->find('css', '[data-test-carrier-tracking-unavailable]'),
            'The buyer is not told that the status is not available.',
        );
    }

    #[Then('I should not see any status of my shipment')]
    public function iShouldNotSeeAnyStatusOfMyShipment(): void
    {
        Assert::null(
            $this->tracking()->find('css', '[data-test-carrier-tracking-status]'),
            'A status of the shipment is shown.',
        );
    }

    #[Then('I should not be told anything about where my order is')]
    public function iShouldNotBeToldAnythingAboutWhereMyOrderIs(): void
    {
        Assert::null(
            $this->session->getPage()->find('css', '[data-test-carrier-tracking]'),
            'The order says something about where it is.',
        );
    }

    #[Then('/^(UPS|FedEx) should not have been asked where the shipment is$/')]
    public function theCarrierShouldNotHaveBeenAskedWhereTheShipmentIs(string $carrierName): void
    {
        Assert::same($this->fakeCarrierState->trackCalls(self::carrierCode($carrierName)), 0);
    }

    #[Then('/^(UPS|FedEx) should have been asked once where the shipment is$/')]
    public function theCarrierShouldHaveBeenAskedOnceWhereTheShipmentIs(string $carrierName): void
    {
        Assert::same($this->fakeCarrierState->trackCalls(self::carrierCode($carrierName)), 1);
    }

    private function tracking(): NodeElement
    {
        $element = $this->session->getPage()->find('css', '[data-test-carrier-tracking]');
        Assert::isInstanceOf($element, NodeElement::class, 'The order says nothing about where it is.');

        return $element;
    }

    private static function carrierCode(string $carrierName): string
    {
        return 'FedEx' === $carrierName ? CarrierCredentialsInterface::CARRIER_FEDEX : CarrierCredentialsInterface::CARRIER_UPS;
    }
}
