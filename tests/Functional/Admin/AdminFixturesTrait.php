<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use Sylius\Component\Addressing\Model\Country;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;

/**
 * Data the admin tests need, created inside the transaction each test rolls back.
 *
 * @property EntityManagerInterface $entityManager
 */
trait AdminFixturesTrait
{
    private function createAdmin(string $username): AdminUser
    {
        $admin = new AdminUser();
        $admin->setEmail($username . '@example.com');
        $admin->setUsername($username);
        $admin->setPlainPassword('sylius');
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }

    /**
     * A Sylius channel cannot exist without a default locale and a base currency: both columns are NOT
     * NULL in sylius_channel. Both are unique by code, so channels in the same test share them.
     */
    private function createChannel(string $code): ChannelInterface
    {
        $locale = $this->entityManager->getRepository(Locale::class)->findOneBy(['code' => 'en_US']);
        if (null === $locale) {
            $locale = new Locale();
            $locale->setCode('en_US');
            $this->entityManager->persist($locale);
        }

        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if (null === $currency) {
            $currency = new Currency();
            $currency->setCode('EUR');
            $this->entityManager->persist($currency);
        }

        $channel = new Channel();
        $channel->setCode($code);
        $channel->setName($code);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setTaxCalculationStrategy('order_items_based');

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $channel;
    }

    private function createCountry(string $code): void
    {
        $country = new Country();
        $country->setCode($code);
        $country->setEnabled(true);

        $this->entityManager->persist($country);
        $this->entityManager->flush();
    }

    private function createOrigin(ChannelInterface $channel, string $weightUnit = 'lb', string $dimensionUnit = 'in'): CarrierShippingOrigin
    {
        $origin = new CarrierShippingOrigin();
        $origin->setChannel($channel);
        $origin->setCompanyName('Swaypc');
        $origin->setContactName('Juan Pablo Moreno Martin');
        $origin->setPhone('13057800955');
        $origin->setStreet('Gran Via 1');
        $origin->setCity('Madrid');
        $origin->setPostcode('28013');
        $origin->setCountryCode('ES');
        $origin->setWeightUnit($weightUnit);
        $origin->setDimensionUnit($dimensionUnit);
        $origin->setDefaultDestinationType('residential');

        $this->entityManager->persist($origin);
        $this->entityManager->flush();

        return $origin;
    }

    private function createBox(string $name): CarrierPackageBox
    {
        $box = new CarrierPackageBox();
        $box->setName($name);
        $box->setInnerLength(12.0);
        $box->setInnerWidth(10.0);
        $box->setInnerHeight(8.0);
        $box->setOuterLength(13.0);
        $box->setOuterWidth(11.0);
        $box->setOuterHeight(9.0);
        $box->setEmptyWeight(0.5);
        $box->setMaxWeight(30.0);

        $this->entityManager->persist($box);
        $this->entityManager->flush();

        return $box;
    }

    private function createCarrierOrder(ChannelInterface $channel, string $calculator): OrderInterface
    {
        $order = new Order();
        $order->setChannel($channel);
        $order->setCurrencyCode('EUR');
        $order->setLocaleCode('en_US');
        // The admin refuses to show a cart: findOrderById leaves that state out.
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCustomer($this->createCustomer());

        $shipment = new Shipment();
        // The shipments grid leaves carts out, the same way the admin order page does.
        $shipment->setState(ShipmentInterface::STATE_READY);
        $shipment->setMethod($this->createShippingMethod($channel, $calculator));
        $order->addShipment($shipment);

        $this->entityManager->persist($order);
        $this->entityManager->persist($shipment);
        $this->entityManager->flush();

        return $order;
    }

    private function createCustomer(): CustomerInterface
    {
        $customer = new Customer();
        $customer->setEmail(sprintf('buyer-%s@example.com', bin2hex(random_bytes(4))));
        $customer->setFirstName('Grace');
        $customer->setLastName('Hopper');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function createShippingMethod(ChannelInterface $channel, string $calculator): ShippingMethodInterface
    {
        $zone = new Zone();
        $zone->setCode('labels-zone-' . bin2hex(random_bytes(4)));
        $zone->setName('Labels zone');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);

        $method = new ShippingMethod();
        $method->setCode('labels-method-' . bin2hex(random_bytes(4)));
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Labels method');
        $method->setCalculator($calculator);
        $method->setConfiguration('flat_rate' === $calculator ? [(string) $channel->getCode() => ['amount' => 500]] : ['service' => '03']);
        $method->setZone($zone);

        $this->entityManager->persist($zone);
        $this->entityManager->persist($method);
        $this->entityManager->flush();

        return $method;
    }
}
