<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackaging;
use JpmMartin\SyliusShippingCarriersPlugin\OrderProcessing\ShippingChargeSourceProcessor;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A store that had its own flat rate shipping method before the plugin arrived keeps it exactly as it was.
 *
 * The store here is in the state of one that just installed the plugin: it is loaded, and nothing of it is
 * configured — no credentials, no shipping origin and no carrier shipping method. Every number asserted is the one
 * Sylius gives on its own, so anything the plugin did to that method would show up as a difference.
 */
final class ExistingShippingMethodsAreUntouchedTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->entityManager->beginTransaction();

        $this->createStore(withCarrierMethod: false);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testTheShippingMethodOfTheStoreIsStillOfferedWithItsOwnFee(): void
    {
        $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);

        $crawler = $this->client->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('input[type="radio"][value="%s"]', self::FLAT_RATE_METHOD)));
        self::assertStringContainsString('$7.99', $crawler->filter('form[name="sylius_shop_checkout_select_shipping"]')->text());
    }

    public function testTheOrderIsCompletedAndChargedTheStoreSOwnAmount(): void
    {
        $order = $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);

        $this->completeTheCheckoutInTheShop(self::FLAT_RATE_METHOD);

        $this->entityManager->clear();
        $placed = $this->entityManager->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $placed);
        self::assertSame(OrderCheckoutStates::STATE_COMPLETED, $placed->getCheckoutState());
        self::assertSame(self::FLAT_RATE_AMOUNT, $placed->getShippingTotal());
    }

    /**
     * The adjustment keeps Sylius's own amount and label, with none of the details the plugin writes on the ones it
     * does quote.
     */
    public function testTheShippingAdjustmentIsStillSyliusSOwn(): void
    {
        $shipment = $this->placeTheOrderAndReadItsShipment();

        $adjustments = $shipment->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT);
        self::assertCount(1, $adjustments);
        $adjustment = $adjustments->first();
        self::assertInstanceOf(AdjustmentInterface::class, $adjustment);
        self::assertSame(self::FLAT_RATE_AMOUNT, $adjustment->getAmount());
        self::assertSame('Standard delivery', $adjustment->getLabel());
        self::assertArrayNotHasKey(ShippingChargeSourceProcessor::DETAIL, $adjustment->getDetails());
    }

    public function testConfirmingTheOrderStoresNoPackagesForAShipmentThatIsNotThePluginS(): void
    {
        $shipment = $this->placeTheOrderAndReadItsShipment();

        self::assertNull(
            $this->entityManager->getRepository(CarrierShipmentPackaging::class)->findOneBy(['shipment' => $shipment]),
        );
    }

    private function placeTheOrderAndReadItsShipment(): ShipmentInterface
    {
        $order = $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);

        $this->completeTheCheckoutInTheShop(self::FLAT_RATE_METHOD);

        $this->entityManager->clear();
        $placed = $this->entityManager->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $placed);
        $shipment = $placed->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);

        return $shipment;
    }
}
