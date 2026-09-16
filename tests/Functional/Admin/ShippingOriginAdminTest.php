<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use Sylius\Component\Addressing\Model\Country;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShippingOriginAdminTest extends WebTestCase
{
    private const FORM = 'jpmmartin_carrier_shipping_origin';

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

        $this->client->loginUser($this->createAdmin(), 'admin');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * CA-1: the administrator creates, edits and deletes an origin from the admin.
     */
    public function testTheAdministratorCreatesEditsAndDeletesAnOrigin(): void
    {
        $channel = $this->createChannel('web-admin-origin');
        $this->createCountry('ES');

        $crawler = $this->client->request('GET', '/admin/shipping-origins/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[channel]' => 'web-admin-origin',
            self::FORM . '[street]' => 'Gran Via 1',
            self::FORM . '[city]' => 'Madrid',
            self::FORM . '[postcode]' => '28013',
            self::FORM . '[countryCode]' => 'ES',
            self::FORM . '[weightUnit]' => 'kg',
            self::FORM . '[dimensionUnit]' => 'cm',
            self::FORM . '[maxPackageWeight]' => '68',
        ]));
        self::assertResponseRedirects();

        $origin = $this->findOriginOf($channel);
        self::assertSame('Madrid', $origin->getCity());
        self::assertSame('kg', $origin->getWeightUnit());
        self::assertSame(68.0, $origin->getMaxPackageWeight());
        $id = $origin->getId();

        $crawler = $this->client->request('GET', sprintf('/admin/shipping-origins/%d/edit', $id));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[city]' => 'Barcelona',
        ]));
        self::assertResponseRedirects();

        self::assertSame('Barcelona', $this->findOriginOf($channel)->getCity());

        // Deleted through the confirmation form the index renders, which carries the CSRF token.
        $crawler = $this->client->request('GET', '/admin/shipping-origins/');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[action$="/admin/shipping-origins/%d"]', $id))->form());
        self::assertResponseRedirects();

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(CarrierShippingOrigin::class, $id));
    }

    public function testASecondOriginForTheSameChannelIsAFormErrorNotAServerError(): void
    {
        $channel = $this->createChannel('web-admin-duplicate');
        $this->createCountry('ES');

        $existing = new CarrierShippingOrigin();
        $existing->setChannel($channel);
        $existing->setStreet('Gran Via 1');
        $existing->setCity('Madrid');
        $existing->setPostcode('28013');
        $existing->setCountryCode('ES');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/shipping-origins/new');

        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[channel]' => 'web-admin-duplicate',
            self::FORM . '[street]' => 'Diagonal 1',
            self::FORM . '[city]' => 'Barcelona',
            self::FORM . '[postcode]' => '08019',
            self::FORM . '[countryCode]' => 'ES',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'This channel already has a shipping origin.');
    }

    private function findOriginOf(ChannelInterface $channel): CarrierShippingOrigin
    {
        $this->entityManager->clear();

        $origin = $this->entityManager->getRepository(CarrierShippingOrigin::class)->findOneBy(['channel' => $channel->getId()]);
        self::assertInstanceOf(CarrierShippingOrigin::class, $origin);

        return $origin;
    }

    private function createAdmin(): AdminUser
    {
        $admin = new AdminUser();
        $admin->setEmail('origin-admin@example.com');
        $admin->setUsername('origin-admin');
        $admin->setPlainPassword('sylius');
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }

    private function createCountry(string $code): void
    {
        $country = new Country();
        $country->setCode($code);
        $country->setEnabled(true);

        $this->entityManager->persist($country);
        $this->entityManager->flush();
    }

    /**
     * A Sylius channel cannot exist without a default locale and a base currency: both columns are
     * NOT NULL in sylius_channel.
     */
    private function createChannel(string $code): ChannelInterface
    {
        $locale = new Locale();
        $locale->setCode('en_US');

        $currency = new Currency();
        $currency->setCode('EUR');

        $channel = new Channel();
        $channel->setCode($code);
        $channel->setName($code);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setTaxCalculationStrategy('order_items_based');

        $this->entityManager->persist($locale);
        $this->entityManager->persist($currency);
        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $channel;
    }
}
