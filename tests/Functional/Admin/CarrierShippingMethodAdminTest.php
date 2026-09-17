<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Addressing\Model\Scope;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Addressing\Model\ZoneMember;
use Sylius\Component\Core\Model\ShippingMethod;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A carrier's service is set up in the admin's shipping methods, like any other shipping method of Sylius.
 */
final class CarrierShippingMethodAdminTest extends WebTestCase
{
    use AdminFixturesTrait;

    private const FORM = 'sylius_admin_shipping_method';

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

        $this->client->loginUser($this->createAdmin('shipping-method-admin'), 'admin');
        $this->createChannel('WEB_US');
        $this->createChannel('WEB_EU');
        $this->createZone('US');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testBothCarriersAreOfferedAmongTheCalculatorsOfAShippingMethod(): void
    {
        $crawler = $this->client->request('GET', '/admin/shipping-methods/new');

        self::assertResponseIsSuccessful();
        self::assertSame('UPS rates', trim($crawler->filter(sprintf('select[name="%s[calculator]"] option[value="ups_rate"]', self::FORM))->text()));
        self::assertSame('FedEx rates', trim($crawler->filter(sprintf('select[name="%s[calculator]"] option[value="fedex_rate"]', self::FORM))->text()));
    }

    /**
     * The flat amount is set by channel, each in the channel's currency, as with Sylius's own flat rate.
     */
    public function testTheAdministratorSetsUpAUpsServiceWithAFlatAmountByChannel(): void
    {
        $this->submitNewShippingMethod('UPS_GROUND', [
            'service' => '03',
            'failure_policy' => 'flat',
            'flat_amount' => ['WEB_US' => '12.00', 'WEB_EU' => '11.00'],
        ]);
        self::assertResponseRedirects();

        $shippingMethod = $this->findShippingMethod('UPS_GROUND');
        self::assertSame('ups_rate', $shippingMethod->getCalculator());
        // Compared regardless of key order, which the stored JSON does not keep.
        self::assertEquals(
            ['service' => '03', 'failure_policy' => 'flat', 'flat_amount' => ['WEB_US' => 1200, 'WEB_EU' => 1100]],
            $shippingMethod->getConfiguration(),
        );

        // Editing shows what was saved.
        $crawler = $this->client->request('GET', sprintf('/admin/shipping-methods/%d/edit', $shippingMethod->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('03', $crawler->filter(sprintf('select[name="%s[configuration][service]"] option[selected]', self::FORM))->attr('value'));
        self::assertSame('flat', $crawler->filter(sprintf('input[name="%s[configuration][failure_policy]"][checked]', self::FORM))->attr('value'));
        self::assertSame('11.00', $crawler->filter(sprintf('input[name="%s[configuration][flat_amount][WEB_EU]"]', self::FORM))->attr('value'));
    }

    /**
     * Left unset, a shipping method hides when its carrier does not answer, and needs no flat amount.
     */
    public function testAShippingMethodWithoutAFailurePolicyHides(): void
    {
        $this->submitNewShippingMethod('UPS_NEXT_DAY', ['service' => '01']);
        self::assertResponseRedirects();

        self::assertSame('hide', $this->findShippingMethod('UPS_NEXT_DAY')->getConfiguration()['failure_policy'] ?? null);
    }

    public function testTwoShippingMethodsOfUpsRepresentDifferentServices(): void
    {
        $this->submitNewShippingMethod('UPS_GROUND', ['service' => '03', 'failure_policy' => 'hide']);
        self::assertResponseRedirects();
        $this->submitNewShippingMethod('UPS_NEXT_DAY', ['service' => '01', 'failure_policy' => 'hide']);
        self::assertResponseRedirects();

        self::assertSame('03', $this->findShippingMethod('UPS_GROUND')->getConfiguration()['service'] ?? null);
        self::assertSame('01', $this->findShippingMethod('UPS_NEXT_DAY')->getConfiguration()['service'] ?? null);
    }

    public function testTwoShippingMethodsOfFedexRepresentDifferentServices(): void
    {
        $this->submitNewShippingMethod('FEDEX_GROUND', [
            'service' => 'FEDEX_GROUND',
            'failure_policy' => 'flat',
            'flat_amount' => ['WEB_US' => '9.50', 'WEB_EU' => '8.50'],
        ], 'fedex_rate');
        self::assertResponseRedirects();
        $this->submitNewShippingMethod('FEDEX_PRIORITY', ['service' => 'PRIORITY_OVERNIGHT', 'failure_policy' => 'hide'], 'fedex_rate');
        self::assertResponseRedirects();

        $ground = $this->findShippingMethod('FEDEX_GROUND');
        self::assertSame('fedex_rate', $ground->getCalculator());
        self::assertEquals(
            ['service' => 'FEDEX_GROUND', 'failure_policy' => 'flat', 'flat_amount' => ['WEB_US' => 950, 'WEB_EU' => 850]],
            $ground->getConfiguration(),
        );
        self::assertSame('PRIORITY_OVERNIGHT', $this->findShippingMethod('FEDEX_PRIORITY')->getConfiguration()['service'] ?? null);
    }

    /**
     * Each carrier offers its own services: a UPS code means nothing to FedEx.
     */
    public function testAFedexShippingMethodRefusesAUpsService(): void
    {
        $this->submitNewShippingMethod('FEDEX_WRONG', ['service' => '03', 'failure_policy' => 'hide'], 'fedex_rate');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->entityManager->getRepository(ShippingMethod::class)->findOneBy(['code' => 'FEDEX_WRONG']));
    }

    /**
     * FedEx shipping methods are checked with their own validation group.
     */
    public function testAFedexShippingMethodWithoutAServiceIsNotSaved(): void
    {
        $crawler = $this->submitNewShippingMethod('FEDEX_NONE', ['service' => '', 'failure_policy' => 'hide'], 'fedex_rate');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose one of the services of this carrier.', $crawler->text());
    }

    public function testAFlatAmountMissingForAChannelIsNotSaved(): void
    {
        $crawler = $this->submitNewShippingMethod('UPS_GROUND', [
            'service' => '03',
            'failure_policy' => 'flat',
            'flat_amount' => ['WEB_US' => '12.00', 'WEB_EU' => ''],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter the flat amount for the channel WEB_EU.', $crawler->text());
        self::assertNull($this->entityManager->getRepository(ShippingMethod::class)->findOneBy(['code' => 'UPS_GROUND']));
    }

    public function testAShippingMethodWithoutAServiceIsNotSaved(): void
    {
        $crawler = $this->submitNewShippingMethod('UPS_NONE', ['service' => '', 'failure_policy' => 'hide']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose one of the services of this carrier.', $crawler->text());
        self::assertNull($this->entityManager->getRepository(ShippingMethod::class)->findOneBy(['code' => 'UPS_NONE']));
    }

    /**
     * The service list refuses it before the plugin's own check does.
     */
    public function testAServiceOutsideTheListIsNotSaved(): void
    {
        $this->submitNewShippingMethod('UPS_UNKNOWN', ['service' => '99', 'failure_policy' => 'hide']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->entityManager->getRepository(ShippingMethod::class)->findOneBy(['code' => 'UPS_UNKNOWN']));
    }

    /**
     * The configuration form is only in the page once a calculator is chosen, so the values are sent as the browser
     * would after choosing it, with the page's CSRF token.
     *
     * @param array<string, mixed> $configuration
     */
    private function submitNewShippingMethod(string $code, array $configuration, string $calculator = 'ups_rate'): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/shipping-methods/new');
        self::assertResponseIsSuccessful();

        /** @var array<string, array<string, mixed>> $values */
        $values = $crawler->filter(sprintf('form[name="%s"]', self::FORM))->form()->getPhpValues();
        $values[self::FORM] = array_replace($values[self::FORM], [
            'code' => $code,
            'zone' => 'US',
            'calculator' => $calculator,
            'channels' => ['WEB_US', 'WEB_EU'],
            'translations' => ['en_US' => ['name' => $code]],
            'configuration' => $configuration,
        ]);

        return $this->client->request('POST', '/admin/shipping-methods/new', $values);
    }

    private function findShippingMethod(string $code): ShippingMethod
    {
        $this->entityManager->clear();
        $shippingMethod = $this->entityManager->getRepository(ShippingMethod::class)->findOneBy(['code' => $code]);
        self::assertInstanceOf(ShippingMethod::class, $shippingMethod);

        return $shippingMethod;
    }

    private function createZone(string $countryCode): void
    {
        $member = new ZoneMember();
        $member->setCode($countryCode);

        $zone = new Zone();
        $zone->setCode($countryCode);
        $zone->setName($countryCode);
        $zone->setType(ZoneInterface::TYPE_COUNTRY);
        $zone->setScope(Scope::ALL);
        $zone->addMember($member);

        $this->entityManager->persist($zone);
        $this->entityManager->flush();
    }
}
