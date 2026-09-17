<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Addressing\Model\Country;
use Sylius\Component\Addressing\Model\Scope;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Addressing\Model\ZoneMember;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

/**
 * A store in the United States with a UPS Ground shipping method, a payment method and a mug to buy, created inside
 * the transaction each test rolls back.
 *
 * @property KernelBrowser $client
 * @property EntityManagerInterface $entityManager
 */
trait ShopCheckoutFixturesTrait
{
    private const CHANNEL = 'WEB-CARRIER';

    private ChannelInterface $channel;

    private ShippingMethodInterface $upsGround;

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
