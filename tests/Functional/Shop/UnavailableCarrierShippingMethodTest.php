<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use Sylius\Component\Addressing\Model\Country;
use Sylius\Component\Addressing\Model\Scope;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Addressing\Model\ZoneMember;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\FakeRateProvider;

/**
 * A UPS shipping method that hides when UPS fails, with UPS down and no rate to fall back on, is unavailable: it is
 * not offered, not assigned by default, and an order cannot be completed with it, so no shipment goes out
 * uncharged.
 */
final class UnavailableCarrierShippingMethodTest extends WebTestCase
{
    private const CHANNEL = 'WEB-CARRIER';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private FakeRateProvider $rateProvider;

    private ChannelInterface $channel;

    private ShippingMethodInterface $upsGround;

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

    private function createStore(): void
    {
        $locale = new Locale();
        $locale->setCode('en_US');
        $currency = new Currency();
        $currency->setCode('USD');
        $country = new Country();
        $country->setCode('US');
        $country->setEnabled(true);

        $channel = new Channel();
        $channel->setCode(self::CHANNEL);
        $channel->setName('Web');
        $channel->setHostname('localhost');
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->addCountry($country);
        $channel->setTaxCalculationStrategy('order_items_based');
        $this->channel = $channel;

        $member = new ZoneMember();
        $member->setCode('US');
        $zone = new Zone();
        $zone->setCode('US-CARRIER');
        $zone->setName('United States');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);
        $zone->setScope(Scope::ALL);
        $zone->addMember($member);

        $upsGround = new ShippingMethod();
        $upsGround->setCode('UPS_GROUND');
        $upsGround->setCurrentLocale('en_US');
        $upsGround->setFallbackLocale('en_US');
        $upsGround->setName('UPS Ground');
        $upsGround->setZone($zone);
        $upsGround->setCalculator('ups_rate');
        $upsGround->setConfiguration(['service' => '03', 'failure_policy' => 'hide']);
        $upsGround->addChannel($channel);
        $upsGround->setEnabled(true);
        $this->upsGround = $upsGround;

        $paymentMethodFactory = self::getContainer()->get('sylius.factory.payment_method');
        self::assertInstanceOf(PaymentMethodFactoryInterface::class, $paymentMethodFactory);
        $paymentMethod = $paymentMethodFactory->createWithGateway('offline');
        $paymentMethod->setCode('CASH-CARRIER');
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setName('Cash on delivery');
        $paymentMethod->getGatewayConfig()?->setGatewayName('offline');
        $paymentMethod->addChannel($channel);
        $paymentMethod->setEnabled(true);

        $variant = new ProductVariant();
        $variant->setCode('CARRIER-MUG');
        $variant->setCurrentLocale('en_US');
        $variant->setFallbackLocale('en_US');
        $variant->setTracked(false);
        $pricing = new ChannelPricing();
        $pricing->setChannelCode(self::CHANNEL);
        $pricing->setPrice(1000);
        $variant->addChannelPricing($pricing);

        $product = new Product();
        $product->setCode('CARRIER-MUG');
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Mug');
        $product->setSlug('carrier-mug');
        $product->addChannel($channel);
        $product->addVariant($variant);

        foreach ([$locale, $currency, $country, $channel, $zone, $upsGround, $paymentMethod, $product] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    /**
     * A cart with one mug, addressed in the United States and processed the way the shop processes it.
     */
    private function createCart(string $checkoutState): OrderInterface
    {
        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = self::getContainer()->get('sylius.factory.order');
        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = self::getContainer()->get('sylius.factory.order_item');
        $quantityModifier = self::getContainer()->get('sylius.modifier.order_item_quantity');
        self::assertInstanceOf(OrderItemQuantityModifierInterface::class, $quantityModifier);
        $orderProcessor = self::getContainer()->get('sylius.order_processing.order_processor');
        self::assertInstanceOf(OrderProcessorInterface::class, $orderProcessor);

        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy(['code' => 'CARRIER-MUG']);
        self::assertInstanceOf(ProductVariant::class, $variant);

        $customer = new Customer();
        $customer->setEmail(sprintf('buyer-%s@example.com', bin2hex(random_bytes(4))));

        $order = $orderFactory->createNew();
        $order->setChannel($this->channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $order->setCustomer($customer);
        $order->setTokenValue(bin2hex(random_bytes(10)));
        $order->setShippingAddress($this->address());
        $order->setBillingAddress($this->address());

        $item = $itemFactory->createNew();
        $item->setVariant($variant);
        $quantityModifier->modify($item, 1);
        $order->addItem($item);

        $orderProcessor->process($order);
        $order->setCheckoutState($checkoutState);

        $this->entityManager->persist($customer);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function createCartInSession(string $checkoutState): OrderInterface
    {
        $order = $this->createCart($checkoutState);

        $sessionFactory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $sessionFactory);
        $session = $sessionFactory->createSession();
        $session->set('_sylius.cart.' . self::CHANNEL, $order->getId());
        $session->save();
        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $order;
    }

    private function address(): Address
    {
        $address = new Address();
        $address->setFirstName('Ada');
        $address->setLastName('Lovelace');
        $address->setStreet('500 Pine St');
        $address->setCity('Seattle');
        $address->setPostcode('98101');
        $address->setCountryCode('US');

        return $address;
    }
}
