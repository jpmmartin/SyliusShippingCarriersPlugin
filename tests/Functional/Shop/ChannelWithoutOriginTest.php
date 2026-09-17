<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;

/**
 * A channel nobody has given a shipping origin offers none of the plugin's shipping methods, and says why in the
 * log, once per request.
 */
final class ChannelWithoutOriginTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

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

        $this->createStore();
        $this->createLocalCourier();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testNoShippingMethodOfThePluginIsOfferedAndTheChannelIsLoggedOnce(): void
    {
        $this->createCartInSession(OrderCheckoutStates::STATE_ADDRESSED);
        // Creating the cart already processed it in this process; a new request starts with the services reset.
        $servicesResetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ServicesResetterInterface::class, $servicesResetter);
        $servicesResetter->reset();
        $log = $this->logFile();
        $logSizeBefore = is_file($log) ? (int) filesize($log) : 0;

        $crawler = $this->client->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[type="radio"][value="LOCAL_COURIER"]'));
        self::assertCount(0, $crawler->filter('input[type="radio"][value="UPS_GROUND"]'));

        clearstatcache();
        $written = (string) file_get_contents($log, offset: $logSizeBefore);
        self::assertSame(1, substr_count($written, 'The channel WEB-CARRIER has no shipping origin, so no carrier shipping method is offered in it.'));
    }

    /**
     * A shipping method of Sylius's own, so the cart has a shipment and the shipping step has methods to weigh.
     */
    private function createLocalCourier(): void
    {
        $zone = $this->upsGround->getZone();
        self::assertNotNull($zone);

        $courier = new ShippingMethod();
        $courier->setCode('LOCAL_COURIER');
        $courier->setCurrentLocale('en_US');
        $courier->setFallbackLocale('en_US');
        $courier->setName('Local courier');
        $courier->setZone($zone);
        $courier->setCalculator('flat_rate');
        $courier->setConfiguration([self::CHANNEL => ['amount' => 500]]);
        $courier->addChannel($this->channel);
        $courier->setEnabled(true);

        $this->entityManager->persist($courier);
        $this->entityManager->flush();
    }

    /**
     * Written by the application's logger at the error level, so it reaches a production log that only writes when
     * an error happens.
     */
    private function logFile(): string
    {
        $logsDir = self::getContainer()->getParameter('kernel.logs_dir');
        self::assertIsString($logsDir);

        return $logsDir . '/test.log';
    }
}
