<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;

/**
 * The key was rotated, lost, or the database came from another installation: the store cannot read the carrier
 * credentials it stored. For the buyer that is the carrier being unavailable, never a server error — and the
 * carrier is not asked at all, because what would be sent as the client id is ciphertext.
 *
 * On Doctrine itself, with the credentials really stored and really encrypted with another key. A stand-in
 * repository would never let the entity reach Doctrine, and that is where the checkout broke: every flush of the
 * request tried to decrypt it again.
 */
final class UndecryptableCredentialsInTheCheckoutTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

    private const KEY_PATH_VARIABLE = 'JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private FakeCarrierState $ups;

    private string $keyPath;

    private string $otherKeyPath;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_' . bin2hex(random_bytes(8)) . '.key';
        $this->otherKeyPath = sys_get_temp_dir() . '/jpmmartin_carrier_other_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->otherKeyPath);
        $_SERVER[self::KEY_PATH_VARIABLE] = $this->keyPath;
        $_ENV[self::KEY_PATH_VARIABLE] = $this->keyPath;

        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();

        $ups = self::getContainer()->get('jpmmartin_carrier.behat.fake_carrier_state');
        self::assertInstanceOf(FakeCarrierState::class, $ups);
        $this->ups = $ups;
        $this->ups->reset();

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

        unset($_SERVER[self::KEY_PATH_VARIABLE], $_ENV[self::KEY_PATH_VARIABLE]);
        foreach ([$this->keyPath, $this->otherKeyPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[DataProvider('policies')]
    public function testTheCheckoutKeepsWorkingAndNothingReachesTheCarrier(string $policy): void
    {
        $this->upsGround->setConfiguration(['service' => '03', 'failure_policy' => $policy, 'flat_amount' => [self::CHANNEL => 1200]]);
        $credentialsId = $this->storeCredentials();

        // The buyer got as far as confirming while the credentials could still be read.
        $this->ups->rateService('ups', '03', 1540, 'USD');
        $this->createCartInSession(OrderCheckoutStates::STATE_PAYMENT_SELECTED);
        $this->encryptTheStoredCredentialsWithAnotherKey($credentialsId);
        $callsBefore = $this->ups->calls('ups');

        $this->visit('/en_US/cart/');
        $this->visit('/en_US/checkout/address');
        $this->submitIfThereIsAForm($this->visit('/en_US/checkout/select-shipping'), 'sylius_shop_checkout_select_shipping');
        $this->visit('/en_US/checkout/select-payment');
        $this->submitIfThereIsAForm($this->visit('/en_US/checkout/complete'), 'sylius_checkout_complete');

        self::assertSame($callsBefore, $this->ups->calls('ups'), 'UPS must not be asked with credentials the store cannot read.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function policies(): iterable
    {
        yield 'the method hides' => ['hide'];
        yield 'the method falls back on a flat amount' => ['flat'];
    }

    private function storeCredentials(): int
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_UPS);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => 'ups-client-id',
            CarrierCredentialsInterface::CLIENT_SECRET => 'ups-client-secret',
        ]);

        $this->entityManager->persist($credentials);
        $this->entityManager->flush();

        return (int) $credentials->getId();
    }

    /**
     * Written straight to the column, so the store's own encryption is not involved. Then everything the earlier
     * requests remembered is forgotten, as for a visit that starts after the key changed.
     */
    private function encryptTheStoredCredentialsWithAnotherKey(int $credentialsId): void
    {
        $otherEncrypter = new Encrypter($this->otherKeyPath);
        $this->entityManager->getConnection()->update(
            'jpmmartin_carrier_credentials',
            ['credentials' => json_encode([
                CarrierCredentialsInterface::CLIENT_ID => $otherEncrypter->encrypt('ups-client-id'),
                CarrierCredentialsInterface::CLIENT_SECRET => $otherEncrypter->encrypt('ups-client-secret'),
            ], \JSON_THROW_ON_ERROR)],
            ['id' => $credentialsId],
        );
        $this->entityManager->clear();

        $rates = self::getContainer()->get('jpmmartin_carrier.cache.rates');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rates);
        $rates->clear();

        $servicesResetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ServicesResetterInterface::class, $servicesResetter);
        $servicesResetter->reset();
    }

    private function visit(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        $this->assertNoServerError($url);

        return $crawler;
    }

    private function submitIfThereIsAForm(Crawler $page, string $formName): void
    {
        $form = $page->filter(sprintf('form[name="%s"]', $formName));
        if (0 === $form->count()) {
            return;
        }

        $this->client->submit($form->form());
        $this->assertNoServerError($formName);
    }

    private function assertNoServerError(string $what): void
    {
        self::assertLessThan(500, $this->client->getResponse()->getStatusCode(), sprintf('%s answered with a server error.', $what));
    }
}
