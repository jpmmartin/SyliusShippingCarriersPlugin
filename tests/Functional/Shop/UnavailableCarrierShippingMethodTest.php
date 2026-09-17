<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\FakeRateProvider;

/**
 * A UPS shipping method that hides when UPS fails, with UPS down and no rate to fall back on, is unavailable: it is
 * not offered, not assigned by default, and an order cannot be completed with it, so no shipment goes out
 * uncharged.
 */
final class UnavailableCarrierShippingMethodTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private FakeRateProvider $rateProvider;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();

        // UPS answers whatever each test sets, without packaging or a network call.
        $this->rateProvider = new FakeRateProvider(RateResult::quoted(new Rate('03', 1540, 'USD')));
        self::getContainer()->set('jpmmartin_carrier.rate_provider', $this->rateProvider);

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

    public function testWhileUpsAnswersItsShippingMethodIsOffered(): void
    {
        $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);

        $crawler = $this->client->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[type="radio"][value="UPS_GROUND"]'));
    }

    public function testWithUpsDownTheShippingMethodIsNotOffered(): void
    {
        $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);
        $this->rateProvider->result = RateResult::carrierFailed(null);

        $crawler = $this->client->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[type="radio"][value="UPS_GROUND"]'));
    }

    public function testWithUpsDownTheShippingMethodIsNotAssignedByDefault(): void
    {
        $this->rateProvider->result = RateResult::carrierFailed(null);

        $order = $this->createCart(OrderCheckoutStates::STATE_ADDRESSED);

        foreach ($order->getShipments() as $shipment) {
            self::assertNotSame($this->upsGround, $shipment->getMethod());
        }
    }

    /**
     * The buyer chose UPS while it answered; by the time they complete the order, UPS is down.
     */
    public function testAnOrderCannotBeCompletedInTheShopWithAShippingMethodThatBecameUnavailable(): void
    {
        $order = $this->createCartInSession(OrderCheckoutStates::STATE_PAYMENT_SELECTED);

        $crawler = $this->client->request('GET', '/en_US/checkout/complete');
        self::assertResponseIsSuccessful();

        $this->rateProvider->result = RateResult::carrierFailed(null);
        $this->client->submit($crawler->filter('form[name="sylius_checkout_complete"]')->form());

        self::assertResponseStatusCodeSame(422);
        $this->assertTheBuyerIsToldTheMethodIsUnavailable();
        $this->assertStillACart($order);
    }

    public function testAnOrderCannotBeCompletedThroughTheApiWithAShippingMethodThatBecameUnavailable(): void
    {
        $order = $this->createCart(OrderCheckoutStates::STATE_PAYMENT_SELECTED);
        $this->rateProvider->result = RateResult::carrierFailed(null);

        $this->client->request(
            'PATCH',
            sprintf('/api/v2/shop/orders/%s/complete', (string) $order->getTokenValue()),
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(422);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertIsArray($response['violations'] ?? null);
        self::assertSame(
            ['The shipping method UPS Ground is not available right now. Please choose another shipping method.'],
            array_column($response['violations'], 'message'),
        );
        $this->assertStillACart($order);
    }

    /**
     * With the flat amount as its policy, the shipping method stays available while UPS is down. The shop first
     * shows the buyer the new total, as it does whenever a total changes before completing.
     */
    public function testAnOrderIsCompletedWithTheFlatAmountWhenThatIsThePolicy(): void
    {
        $this->upsGround->setConfiguration(['service' => '03', 'failure_policy' => 'flat', 'flat_amount' => [self::CHANNEL => 1200]]);
        $this->entityManager->flush();
        $order = $this->createCartInSession(OrderCheckoutStates::STATE_PAYMENT_SELECTED);

        $crawler = $this->client->request('GET', '/en_US/checkout/complete');
        self::assertResponseIsSuccessful();

        $this->rateProvider->result = RateResult::carrierFailed(null);
        $this->client->submit($crawler->filter('form[name="sylius_checkout_complete"]')->form());
        self::assertResponseRedirects('/en_US/checkout/complete');

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[name="sylius_checkout_complete"]')->form());

        self::assertResponseRedirects();
        $this->entityManager->clear();
        $completed = $this->entityManager->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $completed);
        self::assertSame(OrderInterface::STATE_NEW, $completed->getState());
        self::assertSame(1200, $completed->getShippingTotal());
        $shipment = $completed->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        $adjustment = $shipment->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT)->first();
        self::assertInstanceOf(AdjustmentInterface::class, $adjustment);
        self::assertSame('flat', $adjustment->getDetails()['carrierRateSource'] ?? null);
    }

    /**
     * Said once in the page, and not blamed on the products of the order as Sylius's own message for an ineligible
     * method does.
     */
    private function assertTheBuyerIsToldTheMethodIsUnavailable(): void
    {
        $content = html_entity_decode((string) $this->client->getResponse()->getContent());

        self::assertSame(1, substr_count($content, 'The shipping method UPS Ground is not available right now. Please choose another shipping method.'));
        self::assertStringNotContainsString('does not fit requirements', $content);
    }

    private function assertStillACart(OrderInterface $order): void
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $reloaded);
        self::assertSame(OrderInterface::STATE_CART, $reloaded->getState());
    }
}
