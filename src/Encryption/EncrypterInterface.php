<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Encryption;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException;

/**
 * The plugin's own encrypter, deliberately independent of `sylius.encrypter`: that service only
 * exists while `sylius_payment.encryption.enabled` is true, and it is keyed with the payment key.
 */
interface EncrypterInterface
{
    public const ENCRYPTION_SUFFIX = '#ENCRYPTED';

    /** @throws EncryptionException */
    public function encrypt(string $data): string;

    /**
     * Data without the encryption suffix is returned as it is.
     *
     * @throws EncryptionException
     */
    public function decrypt(string $data): string;
}
