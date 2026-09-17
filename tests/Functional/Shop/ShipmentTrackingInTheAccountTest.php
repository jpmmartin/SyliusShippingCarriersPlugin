<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingEvent;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShopUser;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\FakeRateProvider;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\FakeTrackingProvider;

/**
 * The buyer opens an order in their account and is told where its shipments are: the carrier's status and events
 * while it answers, and the tracking number with a notice while it does not.
 */
final class ShipmentTrackingInTheAccountTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

    private const TRACKING_NUMBER = '1Z999AA10123456784';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private FakeTrackingProvider $trackingProvider;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();

        // The carrier answers whatever each test sets, without a network call. Both are installed before the first
        // request, while nothing has asked the container for them yet.
        self::getContainer()->set('jpmmartin_carrier.rate_provider', new FakeRateProvider(RateResult::quoted(new Rate('03', 1540, 'USD'))));
        $this->trackingProvider = new FakeTrackingProvider();
        self::getContainer()->set('jpmmartin_carrier.tracking_provider', $this->trackingProvider);

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->entityManager->beginTransaction();

        $this->createStore();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testTheBuyerSeesTheStatusAndTheEventsTheCarrierReports(): void
    {
        $this->trackingProvider->answer = new TrackingInfo(self::TRACKING_NUMBER, 'Delivered', [
            new TrackingEvent(new \DateTimeImmutable('2026-09-16 10:15:00'), 'Delivered', 'Seattle, WA, US'),
            new TrackingEvent(new \DateTimeImmutable('2026-09-15 08:02:00'), 'Out for delivery', 'Seattle, WA, US'),
        ]);

        $crawler = $this->openTheOrderInTheAccount($this->placedOrder(self::TRACKING_NUMBER));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::TRACKING_NUMBER, $crawler->filter('[data-test-carrier-tracking]')->text());
        self::assertSame('Delivered', $crawler->filter('[data-test-carrier-tracking-status]')->text());
        self::assertStringContainsString('Out for delivery', $crawler->filter('[data-test-carrier-tracking-events]')->text());
        self::assertCount(0, $crawler->filter('[data-test-carrier-tracking-unavailable]'));
    }

    /**
     * A carrier that cannot be asked leaves the buyer with the number and a notice, never with an error.
     */
    public function testWhenTheCarrierCannotBeAskedTheBuyerStillSeesTheTrackingNumber(): void
    {
        $this->trackingProvider->answer = null;

        $crawler = $this->openTheOrderInTheAccount($this->placedOrder(self::TRACKING_NUMBER));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::TRACKING_NUMBER, $crawler->filter('[data-test-carrier-tracking-number]')->text());
        self::assertCount(1, $crawler->filter('[data-test-carrier-tracking-unavailable]'));
        self::assertCount(0, $crawler->filter('[data-test-carrier-tracking-status]'));
    }

    public function testAShipmentWithoutATrackingNumberShowsNoTrackingAtAll(): void
    {
        $crawler = $this->openTheOrderInTheAccount($this->placedOrder(null));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-test-carrier-tracking]'));
        self::assertSame(0, $this->trackingProvider->enquiries);
    }

    /**
     * An order the buyer has just placed is opened from their account with the shipment that came out of it.
     */
    private function placedOrder(?string $trackingNumber): OrderInterface
    {
        $cart = $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);
        $this->completeTheCheckoutInTheShop();

        // The requests above left the cart detached, so the order is read again the way any page reads it.
        $this->entityManager->clear();
        $order = $this->entityManager->find($cart::class, $cart->getId());
        self::assertInstanceOf(OrderInterface::class, $order);

        $shipment = $order->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        $shipment->setTracking($trackingNumber);

        $user = new ShopUser();
        $user->setPlainPassword('sylius');
        $user->setEnabled(true);
        $customer = $order->getCustomer();
        self::assertNotNull($customer);
        $user->setCustomer($customer);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        self::assertNotSame(BaseOrderInterface::STATE_CART, $order->getState());
        $this->client->loginUser($user, 'shop');

        return $order;
    }

    private function openTheOrderInTheAccount(OrderInterface $order): Crawler
    {
        return $this->client->request('GET', sprintf('/en_US/account/orders/%s', (string) $order->getNumber()));
    }
}
