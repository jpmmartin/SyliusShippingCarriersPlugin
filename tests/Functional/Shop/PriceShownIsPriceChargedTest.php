<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;

/**
 * The fee shown next to a carrier's shipping method is what the order is charged once the buyer confirms it.
 */
final class PriceShownIsPriceChargedTest extends WebTestCase
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

    public function testTheOrderIsChargedTheFeeShownNextToTheShippingMethod(): void
    {
        $this->ups->rateService('ups', '03', 1540, 'USD');
        $order = $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);

        $crawler = $this->client->request('GET', '/en_US/checkout/select-shipping');
        self::assertResponseIsSuccessful();
        $shownFee = trim($crawler->filter('[data-test-shipping-item]:contains("UPS Ground") [data-test-shipping-method-fee]')->text());
        self::assertSame('$15.40', $shownFee);

        $this->client->submit($crawler->filter('form[name="sylius_shop_checkout_select_shipping"]')->form([
            'sylius_shop_checkout_select_shipping[shipments][0][method]' => 'UPS_GROUND',
        ]));
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', '/en_US/checkout/select-payment');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[name="sylius_shop_checkout_select_payment"]')->form([
            'sylius_shop_checkout_select_payment[payments][0][method]' => 'CASH-CARRIER',
        ]));
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', '/en_US/checkout/complete');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[name="sylius_checkout_complete"]')->form());
        self::assertResponseRedirects();

        $this->entityManager->clear();
        $placed = $this->entityManager->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $placed);
        self::assertSame(OrderInterface::STATE_NEW, $placed->getState());

        $moneyFormatter = self::getContainer()->get('sylius.formatter.money');
        self::assertInstanceOf(MoneyFormatterInterface::class, $moneyFormatter);
        self::assertSame($shownFee, $moneyFormatter->format($placed->getShippingTotal(), 'USD', 'en_US'));

        $shipment = $placed->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        $adjustment = $shipment->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT)->first();
        self::assertInstanceOf(AdjustmentInterface::class, $adjustment);
        self::assertSame('rate', $adjustment->getDetails()['carrierRateSource'] ?? null);
    }
}
