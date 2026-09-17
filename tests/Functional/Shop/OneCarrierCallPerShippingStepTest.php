<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateSet;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Rate\RecordingCarrier;

/**
 * Six UPS services on the shipping step cost a single call to UPS, and coming back costs none.
 *
 * This is what makes carrier rates affordable in a checkout: a call per service would multiply the waiting and the
 * carrier's quota by the number of shipping methods. Everything is real but UPS itself and its credentials.
 */
final class OneCarrierCallPerShippingStepTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

    /** UPS Ground comes with the store; these are the other five. */
    private const OTHER_SERVICES = ['01', '02', '12', '13', '14'];

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private RecordingCarrier $ups;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();

        $this->ups = new RecordingCarrier(new RateSet(array_map(
            static fn (string $service): Rate => new Rate($service, 1000 + (int) $service, 'USD'),
            ['03', ...self::OTHER_SERVICES],
        )));
        self::getContainer()->set('jpmmartin_carrier.carrier.ups', $this->ups);
        self::getContainer()->set('jpmmartin_carrier.carrier.credentials_provider', $this->credentialsProvider());

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->entityManager->beginTransaction();

        $this->createStore();
        $this->createOrigin();
        $this->createOtherUpsServices();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testSixUpsServicesOnTheShippingStepCostOneCallToUps(): void
    {
        $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);
        $this->startAsANewVisit();

        $crawler = $this->client->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('input[type="radio"][value^="UPS_"]'));
        self::assertCount(1, $this->ups->requests);

        // Coming back to the step reads what the first visit stored.
        $this->client->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->ups->requests);
    }

    /**
     * Creating the cart already rated it in this process. A visit from scratch has nothing stored and nothing
     * remembered from an earlier request.
     */
    private function startAsANewVisit(): void
    {
        $rates = self::getContainer()->get('jpmmartin_carrier.cache.rates');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rates);
        $rates->clear();

        $servicesResetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ServicesResetterInterface::class, $servicesResetter);
        $servicesResetter->reset();

        $this->ups->requests = [];
    }

    private function createOrigin(): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setChannel($this->channel);
        $origin->setStreet('1 Main St');
        $origin->setCity('Chicago');
        $origin->setPostcode('60601');
        $origin->setCountryCode('US');
        $origin->setProvinceCode('IL');
        $origin->setDefaultDestinationType('commercial');

        $this->entityManager->persist($origin);
        $this->entityManager->flush();
    }

    private function createOtherUpsServices(): void
    {
        $zone = $this->upsGround->getZone();
        self::assertNotNull($zone);

        foreach (self::OTHER_SERVICES as $position => $service) {
            $method = new ShippingMethod();
            $method->setCode('UPS_' . $service);
            $method->setCurrentLocale('en_US');
            $method->setFallbackLocale('en_US');
            $method->setName('UPS ' . $service);
            $method->setPosition($position + 1);
            $method->setZone($zone);
            $method->setCalculator('ups_rate');
            $method->setConfiguration(['service' => $service, 'failure_policy' => 'hide']);
            $method->addChannel($this->channel);
            $method->setEnabled(true);

            $this->entityManager->persist($method);
        }

        $this->entityManager->flush();
    }

    /**
     * Stored credentials are encrypted with a key the test application does not have, and no real call is made.
     */
    private function credentialsProvider(): CredentialsProvider
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_UPS);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => 'ups-client-id',
            CarrierCredentialsInterface::CLIENT_SECRET => 'ups-client-secret',
        ]);

        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($credentials);

        return new CredentialsProvider($repository);
    }
}
