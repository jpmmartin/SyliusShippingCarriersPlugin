<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Carrier;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use ParagonIE\Halite\KeyFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Credentials saved with one key and read with another: the key was rotated, lost, or the database came from
 * another installation. The store cannot read what it stored, and for the cart and the checkout that has to be
 * the same as having no credentials — never a server error, and never the ciphertext sent to the carrier. All of
 * it on Doctrine itself: a stand-in repository never lets the entity reach it, and that is where it broke.
 */
final class UndecryptableCredentialsTest extends KernelTestCase
{
    private const KEY_PATH_VARIABLE = 'JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH';

    private string $keyPath;

    private string $otherKeyPath;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_' . bin2hex(random_bytes(8)) . '.key';
        $this->otherKeyPath = sys_get_temp_dir() . '/jpmmartin_carrier_other_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->otherKeyPath);

        $_SERVER[self::KEY_PATH_VARIABLE] = $this->keyPath;
        $_ENV[self::KEY_PATH_VARIABLE] = $this->keyPath;

        self::bootKernel();

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

        unset($_SERVER[self::KEY_PATH_VARIABLE], $_ENV[self::KEY_PATH_VARIABLE]);

        foreach ([$this->keyPath, $this->otherKeyPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testCredentialsSavedWithAnotherKeyAreInvalidCredentials(): void
    {
        $this->storeCredentialsEncryptedWithAnotherKey();

        try {
            $this->credentialsProvider()->get(CarrierCredentialsInterface::CARRIER_UPS);
            self::fail('Credentials that cannot be decrypted have to be refused as credentials.');
        } catch (CarrierCredentialsException $exception) {
            self::assertStringContainsString('cannot be decrypted', $exception->getMessage());
        }
    }

    /**
     * The entity stays in memory still encrypted, and Doctrine hands that one back to the next lookup without
     * loading it again. That second lookup must not return the ciphertext as if it were a client id.
     */
    public function testASecondLookupInTheSameRequestIsRefusedToo(): void
    {
        $this->storeCredentialsEncryptedWithAnotherKey();
        $credentialsProvider = $this->credentialsProvider();

        foreach (['first', 'second'] as $lookup) {
            try {
                $credentialsProvider->get(CarrierCredentialsInterface::CARRIER_UPS);
                self::fail(sprintf('The %s lookup has to be refused as well.', $lookup));
            } catch (CarrierCredentialsException $exception) {
                self::assertStringContainsString('cannot be decrypted', $exception->getMessage(), sprintf('The %s lookup.', $lookup));
            }
        }
    }

    /**
     * The one the checkout was dying of: every flush of the request tries to decrypt the credentials in memory
     * again, and the shipping step saves the order. Loaded once, unreadable credentials must not break whatever
     * the request saves afterwards.
     */
    public function testSavingAnythingAfterwardsDoesNotBreakOnThem(): void
    {
        $this->storeCredentialsEncryptedWithAnotherKey();

        try {
            $this->credentialsProvider()->get(CarrierCredentialsInterface::CARRIER_UPS);
        } catch (CarrierCredentialsException) {
            // Refused, as the tests above say; what matters here is what comes after.
        }

        $this->entityManager->flush();
        $this->addToAssertionCount(1);
    }

    /**
     * What the store saves, it reads back: nothing here gets in the way of credentials that are fine.
     */
    public function testCredentialsSavedWithTheStoresOwnKeyAreRead(): void
    {
        $this->storeCredentials();
        $this->entityManager->clear();

        $credentials = $this->credentialsProvider()->get(CarrierCredentialsInterface::CARRIER_UPS);

        self::assertSame('ups-client-id', $credentials->getCredentials()[CarrierCredentialsInterface::CLIENT_ID] ?? null);
    }

    /**
     * Saved through the store with its own key, and then overwritten in the database with values encrypted with
     * a key the store does not have. Written straight to the column so the store's own encryption is not involved.
     */
    private function storeCredentialsEncryptedWithAnotherKey(): void
    {
        $credentials = $this->storeCredentials();

        $otherEncrypter = new Encrypter($this->otherKeyPath);
        $this->entityManager->getConnection()->update(
            'jpmmartin_carrier_credentials',
            ['credentials' => json_encode([
                CarrierCredentialsInterface::CLIENT_ID => $otherEncrypter->encrypt('ups-client-id'),
                CarrierCredentialsInterface::CLIENT_SECRET => $otherEncrypter->encrypt('ups-client-secret'),
            ], \JSON_THROW_ON_ERROR)],
            ['id' => $credentials->getId()],
        );

        $this->entityManager->clear();
    }

    private function storeCredentials(): CarrierCredentialsInterface
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

        return $credentials;
    }

    private function credentialsProvider(): CredentialsProvider
    {
        $credentialsProvider = self::getContainer()->get('jpmmartin_carrier.carrier.credentials_provider');
        self::assertInstanceOf(CredentialsProvider::class, $credentialsProvider);

        return $credentialsProvider;
    }
}
