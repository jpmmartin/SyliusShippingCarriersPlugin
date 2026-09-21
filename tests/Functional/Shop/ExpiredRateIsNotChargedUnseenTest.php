<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;

/**
 * A stored rate does not last forever, and a buyer who takes a while to confirm can meet a carrier that now
 * charges something else. The order is then never confirmed with a total the buyer did not have in front of
 * them: Sylius processes the order once more before confirming it and refuses when the total moved.
 *
 * That guarantee only holds while the plugin really asks the carrier again at that moment and the new rate
 * really moves the total, which is what these tests pin down. How the stored rate came to be stale is the
 * business of the rate lifetime, tested on its own; here the stored rates are simply gone.
 */
final class ExpiredRateIsNotChargedUnseenTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

    private const RATE_SHOWN = 1540;

    private const RATE_AT_CONFIRMATION = 1720;

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
        $this->forgetTheStoredRates();

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

    /**
     * The buyer confirms looking at one total; the carrier now charges another. The order is not confirmed, and
     * the buyer is sent back to the confirmation page, which now shows the new total.
     */
    public function testInTheShopTheOrderIsNotConfirmedAndTheBuyerIsShownTheNewTotal(): void
    {
        $this->ups->rateService('ups', '03', self::RATE_SHOWN, 'USD');
        $order = $this->createCartInSession(OrderCheckoutStates::STATE_PAYMENT_SELECTED);

        $crawler = $this->client->request('GET', '/en_US/checkout/complete');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('$15.40', (string) $this->client->getResponse()->getContent(), 'The buyer confirms looking at the rate that was quoted.');

        $this->ups->rateService('ups', '03', self::RATE_AT_CONFIRMATION, 'USD');
        $this->forgetTheStoredRates();

        $this->client->submit($crawler->filter('form[name="sylius_checkout_complete"]')->form());
        self::assertResponseRedirects('/en_US/checkout/complete');

        $placed = $this->reload($order);
        self::assertSame(OrderInterface::STATE_CART, $placed->getState(), 'An order whose total moved is not confirmed.');
        self::assertSame(self::RATE_AT_CONFIRMATION, $placed->getShippingTotal());

        $this->client->request('GET', '/en_US/checkout/complete');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('$17.20', (string) $this->client->getResponse()->getContent(), 'The buyer is shown the new total before confirming again.');
    }

    public function testThroughTheApiTheOrderIsNotConfirmed(): void
    {
        $this->ups->rateService('ups', '03', self::RATE_SHOWN, 'USD');
        $order = $this->createCart(OrderCheckoutStates::STATE_PAYMENT_SELECTED);

        $this->ups->rateService('ups', '03', self::RATE_AT_CONFIRMATION, 'USD');
        $this->forgetTheStoredRates();

        $this->client->request(
            'PATCH',
            sprintf('/api/v2/shop/orders/%s/complete', (string) $order->getTokenValue()),
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(409);
        self::assertSame(OrderInterface::STATE_CART, $this->reload($order)->getState(), 'An order whose total moved is not confirmed.');
    }

    /**
     * What a stale stored rate amounts to at confirmation: nothing kept, so the carrier is asked again. The
     * services are reset as between two requests, so nothing is remembered from the one that quoted.
     */
    private function forgetTheStoredRates(): void
    {
        $rates = self::getContainer()->get('jpmmartin_carrier.cache.rates');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rates);
        $rates->clear();

        $servicesResetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ServicesResetterInterface::class, $servicesResetter);
        $servicesResetter->reset();
    }

    private function reload(OrderInterface $order): OrderInterface
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $reloaded);

        return $reloaded;
    }
}
