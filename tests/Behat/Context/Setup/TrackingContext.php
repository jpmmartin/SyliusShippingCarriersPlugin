<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;
use Webmozart\Assert\Assert;

final readonly class TrackingContext implements Context
{
    /**
     * @param RepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private SharedStorageInterface $sharedStorage,
        private RepositoryInterface $orderRepository,
        private FakeCarrierState $fakeCarrierState,
    ) {
    }

    #[Given('/^the shipment of this order has tracking number "([^"]+)"$/')]
    public function theShipmentOfThisOrderHasTrackingNumber(string $trackingNumber): void
    {
        $this->shipmentOfThisOrder()->setTracking($trackingNumber);
        $this->orderRepository->add($this->thisOrder());
    }

    #[Given('the shipment of this order has no tracking number')]
    public function theShipmentOfThisOrderHasNoTrackingNumber(): void
    {
        $this->shipmentOfThisOrder()->setTracking(null);
        $this->orderRepository->add($this->thisOrder());
    }

    #[Given('/^(UPS|FedEx) says the shipment is "([^"]+)"$/')]
    public function theCarrierSaysTheShipmentIs(string $carrierName, string $status): void
    {
        $tracking = $this->fakeCarrierState->tracking(self::carrierCode($carrierName));

        $this->fakeCarrierState->trackShipment(self::carrierCode($carrierName), $status, $tracking['events'] ?? []);
    }

    /**
     * The events are dated one hour apart, newest first, the way a carrier reports them.
     */
    #[Given('/^(UPS|FedEx) reports "([^"]+)" in "([^"]+)"$/')]
    public function theCarrierReportsInLocation(string $carrierName, string $description, string $location): void
    {
        $carrier = self::carrierCode($carrierName);
        $happened = \count($this->fakeCarrierState->tracking($carrier)['events'] ?? []);

        $this->fakeCarrierState->addTrackingEvent($carrier, [
            'occurred_at' => (new \DateTimeImmutable(sprintf('-%d hours', $happened)))->format('c'),
            'description' => $description,
            'location' => $location,
        ]);
    }

    #[Given('/^(UPS|FedEx) says nothing about where the shipment is$/')]
    public function theCarrierSaysNothingAboutWhereTheShipmentIs(string $carrierName): void
    {
        $this->fakeCarrierState->trackShipment(self::carrierCode($carrierName), null, []);
    }

    private function shipmentOfThisOrder(): ShipmentInterface
    {
        $shipment = $this->thisOrder()->getShipments()->first();
        Assert::isInstanceOf($shipment, ShipmentInterface::class);

        return $shipment;
    }

    private function thisOrder(): OrderInterface
    {
        $order = $this->sharedStorage->get('order');
        Assert::isInstanceOf($order, OrderInterface::class);

        return $order;
    }

    private static function carrierCode(string $carrierName): string
    {
        return 'FedEx' === $carrierName ? CarrierCredentialsInterface::CARRIER_FEDEX : CarrierCredentialsInterface::CARRIER_UPS;
    }
}
