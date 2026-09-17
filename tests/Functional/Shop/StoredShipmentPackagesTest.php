<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackaging;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackagingInterface;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;

/**
 * Confirming an order stores the packages its shipment was quoted with, in the shop and through the API alike.
 */
final class StoredShipmentPackagesTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private FakeCarrierState $ups;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();
        $this->replaceCredentialsProvider();

        $ups = self::getContainer()->get('jpmmartin_carrier.behat.fake_carrier_state');
        self::assertInstanceOf(FakeCarrierState::class, $ups);
        $this->ups = $ups;
        $this->ups->reset();
        $this->ups->rateService('ups', '03', 1540, 'USD');

        $rates = self::getContainer()->get('jpmmartin_carrier.cache.rates');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rates);
        $rates->clear();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->entityManager->beginTransaction();

        $this->createStore();
        $this->createOrigin();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        $this->ups->reset();

        parent::tearDown();
    }

    public function testAnOrderConfirmedInTheShopKeepsThePackagesItWasQuotedWith(): void
    {
        $order = $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);

        $this->completeTheCheckoutInTheShop();

        $packaging = $this->packagingOf($order);
        self::assertSame(CarrierShipmentPackagingInterface::STATE_PERSISTED, $packaging->getState());
        self::assertCount(1, $packaging->getPackages());

        $package = $packaging->getPackages()->first();
        self::assertNotFalse($package);
        // One mug of 2 lb measuring 10 by 8 by 6 inches, in no box: the store has no box catalog.
        self::assertSame([null, 10.0, 8.0, 6.0, 'in', 2.0, 'lb'], [
            $package->getBoxName(), $package->getLength(), $package->getWidth(), $package->getHeight(),
            $package->getDimensionUnit(), $package->getWeight(), $package->getWeightUnit(),
        ]);
        self::assertCount(1, $package->getUnits());
    }

    public function testAnOrderConfirmedThroughTheApiKeepsThePackagesItWasQuotedWith(): void
    {
        $order = $this->createCart(OrderCheckoutStates::STATE_PAYMENT_SELECTED);

        $this->completeThroughTheApi($order);

        $packaging = $this->packagingOf($order);
        self::assertSame(CarrierShipmentPackagingInterface::STATE_PERSISTED, $packaging->getState());
        self::assertCount(1, $packaging->getPackages());
    }

    private function completeThroughTheApi(OrderInterface $order): void
    {
        $this->client->request(
            'PATCH',
            sprintf('/api/v2/shop/orders/%s/complete', (string) $order->getTokenValue()),
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: '{}',
        );

        self::assertResponseIsSuccessful();
    }

    private function packagingOf(OrderInterface $order): CarrierShipmentPackagingInterface
    {
        $this->entityManager->clear();
        $placed = $this->entityManager->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $placed);
        self::assertSame(OrderInterface::STATE_NEW, $placed->getState());

        $shipment = $placed->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);

        $packaging = $this->entityManager->getRepository(CarrierShipmentPackaging::class)->findOneBy(['shipment' => $shipment->getId()]);
        self::assertInstanceOf(CarrierShipmentPackagingInterface::class, $packaging);

        return $packaging;
    }
}
