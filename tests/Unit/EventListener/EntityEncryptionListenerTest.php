<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncryptionAwareInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EntityEncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\EventListener\EntityEncryptionListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

/**
 * Credentials that cannot be decrypted are left encrypted instead of breaking whatever loads or saves them, and
 * somebody has to find out: the log is where they will look.
 */
final class EntityEncryptionListenerTest extends TestCase
{
    public function testCredentialsThatCannotBeDecryptedAreLeftAsTheyWereAndLogged(): void
    {
        $logger = new RecordingLogger();
        $credentials = new CarrierCredentials();
        $credentials->setCredentials(['client_id' => 'ciphertext#ENCRYPTED']);

        /** @var EntityEncrypterInterface<CarrierCredentials> $encrypter */
        $encrypter = new class() implements EntityEncrypterInterface {
            public function encrypt(EncryptionAwareInterface $entity): void
            {
            }

            public function decrypt(EncryptionAwareInterface $entity): void
            {
                throw EncryptionException::cannotDecrypt(new \RuntimeException('The ciphertext does not match the key.'));
            }
        };

        $listener = new EntityEncryptionListener($encrypter, CarrierCredentials::class, $logger);
        $listener->postLoad(new PostLoadEventArgs($credentials, $this->createStub(EntityManagerInterface::class)));

        self::assertSame(['client_id' => 'ciphertext#ENCRYPTED'], $credentials->getCredentials());
        self::assertSame(LogLevel::WARNING, $logger->records[0][0] ?? null);
    }
}
