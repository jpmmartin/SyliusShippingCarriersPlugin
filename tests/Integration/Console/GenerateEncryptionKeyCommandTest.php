<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Console;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateEncryptionKeyCommandTest extends KernelTestCase
{
    private const KEY_PATH_VARIABLE = 'JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH';

    private string $directory;

    private string $keyPath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jpmmartin_carrier_' . bin2hex(random_bytes(8));
        // In a directory that does not exist yet: the command must create it.
        $this->keyPath = $this->directory . '/encryption/carrier.key';

        $_SERVER[self::KEY_PATH_VARIABLE] = $this->keyPath;
        $_ENV[self::KEY_PATH_VARIABLE] = $this->keyPath;

        self::bootKernel();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER[self::KEY_PATH_VARIABLE], $_ENV[self::KEY_PATH_VARIABLE]);

        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
        foreach ([\dirname($this->keyPath), $this->directory] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testItGeneratesAKeyTheEncrypterCanUse(): void
    {
        $tester = $this->createTester();

        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertFileExists($this->keyPath);

        $encrypter = new Encrypter($this->keyPath);
        self::assertSame('s3cr3t', $encrypter->decrypt($encrypter->encrypt('s3cr3t')));
    }

    /**
     * Replacing the key makes every stored credential unreadable, so an existing key is left alone
     * unless the replacement is explicit.
     */
    public function testItKeepsAnExistingKeyWithoutOverwrite(): void
    {
        $tester = $this->createTester();
        $tester->execute([], ['interactive' => false]);
        $original = (string) file_get_contents($this->keyPath);

        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame($original, file_get_contents($this->keyPath));
    }

    public function testItReplacesAnExistingKeyWithOverwrite(): void
    {
        $tester = $this->createTester();
        $tester->execute([], ['interactive' => false]);
        $original = (string) file_get_contents($this->keyPath);

        $tester->execute(['--overwrite' => true], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertNotSame($original, file_get_contents($this->keyPath));
    }

    private function createTester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('jpmmartin:carrier:generate-key'));
    }
}
