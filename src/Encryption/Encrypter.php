<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Encryption;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException;
use ParagonIE\Halite\Alerts\CannotPerformOperation;
use ParagonIE\Halite\Alerts\HaliteAlert;
use ParagonIE\Halite\Alerts\InvalidKey;
use ParagonIE\Halite\KeyFactory;
use ParagonIE\Halite\Symmetric\Crypto;
use ParagonIE\Halite\Symmetric\EncryptionKey;
use ParagonIE\HiddenString\HiddenString;

/**
 * Same construction as Sylius' payment encrypter (Component/Payment/Encryption/Encrypter.php), on its
 * own key file.
 */
final class Encrypter implements EncrypterInterface
{
    private ?EncryptionKey $key = null;

    public function __construct(
        private readonly string $encryptionKeyPath,
    ) {
    }

    public function encrypt(string $data): string
    {
        try {
            return Crypto::encrypt(new HiddenString($data), $this->getKey()) . self::ENCRYPTION_SUFFIX;
        } catch (HaliteAlert|\SodiumException|\TypeError $exception) {
            throw EncryptionException::cannotEncrypt($exception);
        }
    }

    public function decrypt(string $data): string
    {
        if (!str_ends_with($data, self::ENCRYPTION_SUFFIX)) {
            return $data;
        }

        try {
            return Crypto::decrypt(substr($data, 0, -\strlen(self::ENCRYPTION_SUFFIX)), $this->getKey())->getString();
        } catch (HaliteAlert|\SodiumException|\TypeError $exception) {
            throw EncryptionException::cannotDecrypt($exception);
        }
    }

    private function getKey(): EncryptionKey
    {
        if (null === $this->key) {
            try {
                $this->key = KeyFactory::loadEncryptionKey($this->encryptionKeyPath);
            } catch (CannotPerformOperation|InvalidKey $exception) {
                throw EncryptionException::invalidKey($exception);
            }
        }

        return $this->key;
    }
}
