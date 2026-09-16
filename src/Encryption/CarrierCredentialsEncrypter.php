<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Encryption;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Webmozart\Assert\Assert;

/**
 * Encrypts each credential value on its own, like Sylius' GatewayConfigEncrypter. The values are
 * strings, so they are encrypted as they are: no serialize()/unserialize() round trip.
 *
 * @implements EntityEncrypterInterface<CarrierCredentialsInterface>
 */
final readonly class CarrierCredentialsEncrypter implements EntityEncrypterInterface
{
    public function __construct(
        private EncrypterInterface $encrypter,
    ) {
    }

    public function encrypt(EncryptionAwareInterface $entity): void
    {
        Assert::isInstanceOf($entity, CarrierCredentialsInterface::class);

        $encrypted = [];
        foreach ($entity->getCredentials() as $name => $value) {
            $encrypted[$name] = str_ends_with($value, EncrypterInterface::ENCRYPTION_SUFFIX) ? $value : $this->encrypter->encrypt($value);
        }

        $entity->setCredentials($encrypted);
    }

    public function decrypt(EncryptionAwareInterface $entity): void
    {
        Assert::isInstanceOf($entity, CarrierCredentialsInterface::class);

        $decrypted = [];
        foreach ($entity->getCredentials() as $name => $value) {
            $decrypted[$name] = $this->encrypter->decrypt($value);
        }

        $entity->setCredentials($decrypted);
    }
}
