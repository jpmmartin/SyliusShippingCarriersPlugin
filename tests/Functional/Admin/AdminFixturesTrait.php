<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use Sylius\Component\Addressing\Model\Country;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;

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
}
