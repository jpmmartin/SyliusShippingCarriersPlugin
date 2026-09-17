<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Entity;

use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use ParagonIE\Halite\KeyFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CarrierCredentialsTest extends KernelTestCase
{
    private const KEY_PATH_VARIABLE = 'JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH';

    private string $keyPath;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);

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

        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    /**
     * What the encryption is for: the secret never reaches the database in the clear. It is
     * checked on the raw column, not through the entity, which would show it decrypted.
     */
    public function testTheSecretIsNotStoredInTheClear(): void
    {
        $credentials = $this->createCredentials(CarrierCredentialsInterface::CARRIER_UPS);

        $this->entityManager->persist($credentials);
        $this->entityManager->flush();

        $stored = $this->entityManager->getConnection()->fetchOne(
            'SELECT credentials FROM jpmmartin_carrier_credentials WHERE id = ?',
            [$credentials->getId()],
        );

        self::assertIsString($stored);
        self::assertStringNotContainsString('s3cr3t-value', $stored);
        self::assertStringNotContainsString('client-id-value', $stored);
        self::assertStringContainsString(EncrypterInterface::ENCRYPTION_SUFFIX, $stored);
    }

    public function testTheCredentialsAreReadableAfterFlushAndAfterLoading(): void
    {
        $credentials = $this->createCredentials(CarrierCredentialsInterface::CARRIER_UPS);

        $this->entityManager->persist($credentials);
        $this->entityManager->flush();

        self::assertSame('s3cr3t-value', $credentials->getCredentials()['client_secret']);

        $this->entityManager->clear();
        $loaded = $this->entityManager->find(CarrierCredentials::class, $credentials->getId());

        self::assertInstanceOf(CarrierCredentials::class, $loaded);
        self::assertSame(['client_id' => 'client-id-value', 'client_secret' => 's3cr3t-value'], $loaded->getCredentials());
    }

    /**
     * Doctrine remembers what it loaded. If that stayed the encrypted data, the decrypted values would
     * read as a change and every flush would rewrite the row.
     */
    public function testLoadedCredentialsAreNotRewrittenByAnUnrelatedFlush(): void
    {
        $credentials = $this->createCredentials(CarrierCredentialsInterface::CARRIER_UPS);

        $this->entityManager->persist($credentials);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $loaded = $this->entityManager->find(CarrierCredentials::class, $credentials->getId());
        self::assertInstanceOf(CarrierCredentials::class, $loaded);

        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        self::assertSame([], $unitOfWork->getEntityChangeSet($loaded));
    }

    /**
     * No default that points at production. The database refuses credentials without an
     * environment.
     */
    public function testTheEnvironmentHasNoDefault(): void
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_FEDEX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $credentials->setCredentials(['api_key' => 'key']);

        self::assertNull($credentials->getEnvironment());

        $this->entityManager->persist($credentials);

        $this->expectException(NotNullConstraintViolationException::class);

        $this->entityManager->flush();
    }

    /**
     * How packages reach the carrier changes the rates, so the database refuses credentials without it.
     */
    public function testThePickupTypeHasNoDefault(): void
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_UPS);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setCredentials(['api_key' => 'key']);

        self::assertNull($credentials->getPickupType());

        $this->entityManager->persist($credentials);

        $this->expectException(NotNullConstraintViolationException::class);

        $this->entityManager->flush();
    }

    public function testThereIsOneSetOfCredentialsPerCarrier(): void
    {
        $this->entityManager->persist($this->createCredentials(CarrierCredentialsInterface::CARRIER_UPS));
        $this->entityManager->flush();

        $this->entityManager->persist($this->createCredentials(CarrierCredentialsInterface::CARRIER_UPS));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->flush();
    }

    private function createCredentials(string $carrier): CarrierCredentials
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier($carrier);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $credentials->setCredentials(['client_id' => 'client-id-value', 'client_secret' => 's3cr3t-value']);

        return $credentials;
    }
}
