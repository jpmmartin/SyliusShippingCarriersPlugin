<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestination;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use Sylius\Component\Addressing\Model\Country;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

/**
 * The buyer chooses in the shop checkout whether the order goes to a home or a business.
 */
final class CheckoutDestinationTypeTest extends WebTestCase
{
    private const FORM = 'sylius_shop_checkout_address';

    private const CHANNEL = 'WEB-DESTINATION';

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
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * On a channel that does not ask for a separate shipping address, the shop hides that block and copies the
     * billing address into it: the choice must survive that.
     */
    public function testTheBuyerChoosesAHomeInAChannelWithoutASeparateShippingAddress(): void
    {
        $channel = $this->createChannel();
        self::assertFalse($channel->isShippingAddressInCheckoutRequired());
        $this->createOrigin($channel, 'commercial');
        $order = $this->createCartInSession($channel);

        $crawler = $this->client->request('GET', '/en_US/checkout/address');
        self::assertResponseIsSuccessful();
        // The origin's default is what the buyer sees before choosing.
        self::assertSame('commercial', $crawler->filter(sprintf('input[name="%s[destinationType]"]:checked', self::FORM))->attr('value'));

        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[customer][email]' => 'buyer@example.com',
            self::FORM . '[billingAddress][firstName]' => 'Ada',
            self::FORM . '[billingAddress][lastName]' => 'Lovelace',
            self::FORM . '[billingAddress][street]' => '500 Pine St',
            self::FORM . '[billingAddress][countryCode]' => 'US',
            self::FORM . '[billingAddress][city]' => 'Seattle',
            self::FORM . '[billingAddress][postcode]' => '98101',
            self::FORM . '[destinationType]' => 'residential',
        ]));
        self::assertResponseRedirects();

        $this->entityManager->clear();
        $destination = $this->entityManager->getRepository(CarrierOrderDestination::class)->findOneBy(['order' => $order->getId()]);
        self::assertInstanceOf(CarrierOrderDestination::class, $destination);
        self::assertSame('residential', $destination->getType());
    }

    public function testAChannelWithoutAnOriginDoesNotAskForTheDestinationType(): void
    {
        $this->createCartInSession($this->createChannel());

        $crawler = $this->client->request('GET', '/en_US/checkout/address');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(sprintf('input[name="%s[destinationType]"]', self::FORM)));
    }

    private function createChannel(): ChannelInterface
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

        foreach ([$locale, $currency, $country, $channel] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return $channel;
    }

    private function createOrigin(ChannelInterface $channel, string $defaultDestinationType): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setChannel($channel);
        $origin->setStreet('1 Main St');
        $origin->setCity('Chicago');
        $origin->setPostcode('60601');
        $origin->setCountryCode('US');
        $origin->setDefaultDestinationType($defaultDestinationType);

        $this->entityManager->persist($origin);
        $this->entityManager->flush();
    }

    /**
     * A cart with one item, remembered in the session the way the shop remembers the buyer's cart.
     */
    private function createCartInSession(ChannelInterface $channel): OrderInterface
    {
        $variant = new ProductVariant();
        $variant->setCode('DESTINATION-MUG');
        $variant->setTracked(false);
        $pricing = new ChannelPricing();
        $pricing->setChannelCode(self::CHANNEL);
        $pricing->setPrice(1000);
        $variant->addChannelPricing($pricing);

        $product = new Product();
        $product->setCode('DESTINATION-MUG');
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Mug');
        $product->setSlug('mug');
        $product->addChannel($channel);
        $product->addVariant($variant);
        $this->entityManager->persist($product);

        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = self::getContainer()->get('sylius.factory.order');
        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = self::getContainer()->get('sylius.factory.order_item');
        $quantityModifier = self::getContainer()->get('sylius.modifier.order_item_quantity');
        self::assertInstanceOf(OrderItemQuantityModifierInterface::class, $quantityModifier);
        $orderProcessor = self::getContainer()->get('sylius.order_processing.order_processor');
        self::assertInstanceOf(OrderProcessorInterface::class, $orderProcessor);

        $order = $orderFactory->createNew();
        $order->setChannel($channel);
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');
        $item = $itemFactory->createNew();
        $item->setVariant($variant);
        $quantityModifier->modify($item, 1);
        $order->addItem($item);
        $orderProcessor->process($order);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $sessionFactory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $sessionFactory);
        $session = $sessionFactory->createSession();
        $session->set('_sylius.cart.' . self::CHANNEL, $order->getId());
        $session->save();
        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $order;
    }
}
