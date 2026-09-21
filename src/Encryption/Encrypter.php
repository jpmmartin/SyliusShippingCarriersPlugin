<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Encryption;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException;
use ParagonIE\Halite\Alerts\CannotPerformOperation;
use ParagonIE\Halite\Alerts\HaliteAlert;
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
        return $this->key ??= $this->loadKey();
    }

    /**
     * Read by hand rather than with KeyFactory::loadEncryptionKey(), which hands the whole file to the hex decoder:
     * a key file that ends in a line break — the usual result of pasting a key into a secret — is not hex to it,
     * and it throws something no caller expects. Hex has no whitespace, so trimming it changes no valid key.
     *
     * Whatever else goes wrong reading it is an EncryptionException, which every caller already handles.
     */
    private function loadKey(): EncryptionKey
    {
        $contents = is_readable($this->encryptionKeyPath) ? file_get_contents($this->encryptionKeyPath) : false;
        if (false === $contents) {
            throw EncryptionException::invalidKey(new CannotPerformOperation(sprintf('Cannot read the key file "%s".', $this->encryptionKeyPath)));
        }

        try {
            return KeyFactory::importEncryptionKey(new HiddenString(trim($contents)));
        } catch (HaliteAlert|\RangeException|\SodiumException|\TypeError $exception) {
            throw EncryptionException::invalidKey($exception);
        } finally {
            sodium_memzero($contents);
        }
    }
}
