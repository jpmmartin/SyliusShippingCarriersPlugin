<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Encryption;

/**
 * @template T of EncryptionAwareInterface
 *
 * @internal
 */
interface EntityEncrypterInterface
{
    /** @param T $entity */
    public function encrypt(EncryptionAwareInterface $entity): void;

    /** @param T $entity */
    public function decrypt(EncryptionAwareInterface $entity): void;
}
