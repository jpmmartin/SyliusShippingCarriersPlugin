<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackaging;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackagingInterface;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;

/**
 * What a confirmed order was packed into stays as it was: changing the box catalog, the weight of a variant or the
 * packaging strategy afterwards changes nothing, and nothing packs that shipment again.
 */
final class StoredPackagesDoNotChangeTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private FakeCarrierState $ups;

    private RecordingPackagingStrategy $packagingStrategy;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();
        $this->replaceCredentialsProvider();

        // The store's packaging strategy, which also says whether anything packs a shipment again later.
        $this->packagingStrategy = new RecordingPackagingStrategy();
        self::getContainer()->set('jpmmartin_carrier.packaging_strategy.default', $this->packagingStrategy);

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

    public function testWhatTheOrderWasPackedIntoSurvivesEveryLaterChange(): void
    {
        $order = $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);
        $this->completeTheCheckoutInTheShop();
        $packedInto = $this->storedPackagesOf($order);
        self::assertSame([['Another box', 99.0, 99.0, 99.0, 'in', 99.0, 'lb']], $packedInto);

        $this->packagingStrategy->calls = 0;
        $this->changeTheCatalog();

        // Everything that could look at the order again: processing it, and an administrator opening it.
        $orderProcessor = self::getContainer()->get('sylius.order_processing.order_processor');
        self::assertInstanceOf(OrderProcessorInterface::class, $orderProcessor);
        $placed = $this->entityManager->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $placed);
        $orderProcessor->process($placed);

        $this->client->loginUser($this->createAdmin(), 'admin');
        $this->client->request('GET', sprintf('/admin/orders/%d', (int) $order->getId()));
        self::assertResponseIsSuccessful();

        self::assertSame($packedInto, $this->storedPackagesOf($order));
        self::assertSame(0, $this->packagingStrategy->calls, 'The shipment of a confirmed order was packed again.');
    }

    /**
     * A box that would hold the mug, and a mug that now weighs four times as much.
     */
    private function changeTheCatalog(): void
    {
        $box = new CarrierPackageBox();
        $box->setName('Mug box');
        $box->setInnerLength(12.0);
        $box->setInnerWidth(10.0);
        $box->setInnerHeight(8.0);
        $box->setOuterLength(13.0);
        $box->setOuterWidth(11.0);
        $box->setOuterHeight(9.0);
        $box->setEmptyWeight(0.5);
        $box->setMaxWeight(30.0);
        $this->entityManager->persist($box);

        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy(['code' => 'CARRIER-MUG']);
        self::assertInstanceOf(ProductVariant::class, $variant);
        $variant->setWeight(8.0);

        $this->entityManager->flush();
    }

    /**
     * @return list<array{string|null, float|null, float|null, float|null, string|null, float|null, string|null}>
     */
    private function storedPackagesOf(OrderInterface $order): array
    {
        $this->entityManager->clear();
        $placed = $this->entityManager->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $placed);
        $shipment = $placed->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);

        $packaging = $this->entityManager->getRepository(CarrierShipmentPackaging::class)->findOneBy(['shipment' => $shipment->getId()]);
        self::assertInstanceOf(CarrierShipmentPackagingInterface::class, $packaging);
        self::assertSame(CarrierShipmentPackagingInterface::STATE_PERSISTED, $packaging->getState());

        $packages = [];
        foreach ($packaging->getPackages() as $package) {
            $packages[] = [
                $package->getBoxName(), $package->getLength(), $package->getWidth(), $package->getHeight(),
                $package->getDimensionUnit(), $package->getWeight(), $package->getWeightUnit(),
            ];
        }

        return $packages;
    }

    private function createAdmin(): AdminUser
    {
        $admin = new AdminUser();
        $admin->setEmail('packages-admin@example.com');
        $admin->setUsername('packages-admin');
        $admin->setPlainPassword('sylius');
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }
}
