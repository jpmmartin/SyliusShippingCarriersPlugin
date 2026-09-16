<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Encryption;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\TestCase;

final class EncrypterTest extends TestCase
{
    /** @var list<string> */
    private array $keyPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->keyPaths as $keyPath) {
            if (is_file($keyPath)) {
                unlink($keyPath);
            }
        }
    }

    public function testItDecryptsWhatItEncrypts(): void
    {
        $encrypter = new Encrypter($this->createKey());

        $encrypted = $encrypter->encrypt('{"client_secret":"s3cr3t"}');

        self::assertSame('{"client_secret":"s3cr3t"}', $encrypter->decrypt($encrypted));
    }

    public function testTheEncryptedValueDoesNotContainTheSecretAndIsMarked(): void
    {
        $encrypted = (new Encrypter($this->createKey()))->encrypt('s3cr3t');

        self::assertStringNotContainsString('s3cr3t', $encrypted);
        self::assertStringEndsWith(EncrypterInterface::ENCRYPTION_SUFFIX, $encrypted);
    }

    public function testDataWithoutTheMarkIsReturnedAsItIs(): void
    {
        self::assertSame('plain', (new Encrypter($this->createKey()))->decrypt('plain'));
    }

    public function testAnotherKeyCannotDecrypt(): void
    {
        $encrypted = (new Encrypter($this->createKey()))->encrypt('s3cr3t');

        $this->expectException(EncryptionException::class);

        (new Encrypter($this->createKey()))->decrypt($encrypted);
    }

    public function testAMissingKeyFileIsAnEncryptionException(): void
    {
        $this->expectException(EncryptionException::class);

        (new Encrypter(sys_get_temp_dir() . '/jpmmartin_carrier_missing_' . bin2hex(random_bytes(8)) . '.key'))->encrypt('s3cr3t');
    }

    private function createKey(): string
    {
        $keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $keyPath);
        $this->keyPaths[] = $keyPath;

        return $keyPath;
    }
}
