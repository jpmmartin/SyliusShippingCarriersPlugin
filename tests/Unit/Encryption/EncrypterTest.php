<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Encryption;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * A key pasted into a secret usually picks up a line break at the end. It is the same key: hex has no
     * whitespace, so the store must work with it rather than stop reading every credential it holds.
     *
     * @return iterable<string, array{string}>
     */
    public static function keysWithWhitespaceAround(): iterable
    {
        yield 'a line break at the end' => ["\n"];
        yield 'a Windows line break at the end' => ["\r\n"];
        yield 'spaces around it' => ['  '];
    }

    #[DataProvider('keysWithWhitespaceAround')]
    public function testAKeyWithWhitespaceAroundItIsTheSameKey(string $whitespace): void
    {
        $keyPath = $this->createKey();
        $encrypted = (new Encrypter($keyPath))->encrypt('the client secret');

        file_put_contents($keyPath, $whitespace . trim((string) file_get_contents($keyPath)) . $whitespace);

        self::assertSame('the client secret', (new Encrypter($keyPath))->decrypt($encrypted));
    }

    /**
     * Anything that is not a key is an EncryptionException, which every caller handles, and never whatever the
     * hex decoder happens to throw.
     *
     * @return iterable<string, array{string}>
     */
    public static function filesThatAreNoKey(): iterable
    {
        yield 'text that is not hex' => ['this is not a key'];
        yield 'an empty file' => [''];
        yield 'half a key' => ['3140040'];
    }

    #[DataProvider('filesThatAreNoKey')]
    public function testAFileThatIsNoKeyIsAnEncryptionException(string $contents): void
    {
        $keyPath = $this->createKey();
        file_put_contents($keyPath, $contents);

        $this->expectException(EncryptionException::class);

        (new Encrypter($keyPath))->decrypt('anything' . EncrypterInterface::ENCRYPTION_SUFFIX);
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
