<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Encryption;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use ParagonIE\Halite\KeyFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The test that justifies D-6: the plugin's encryption keeps working when an installation turns
 * Sylius' payment encryption off, which removes the `sylius.encrypter` service altogether.
 *
 * The `no_payment_encryption` environment is configured in tests/TestApplication/config/config.yaml.
 */
final class EncrypterWithoutPaymentEncryptionTest extends KernelTestCase
{
    private const KEY_PATH_VARIABLE = 'JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH';

    private string $keyPath;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);

        $_SERVER[self::KEY_PATH_VARIABLE] = $this->keyPath;
        $_ENV[self::KEY_PATH_VARIABLE] = $this->keyPath;

        self::bootKernel(['environment' => 'no_payment_encryption']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER[self::KEY_PATH_VARIABLE], $_ENV[self::KEY_PATH_VARIABLE]);

        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    public function testThePluginEncryptsWithSyliusPaymentEncryptionDisabled(): void
    {
        $container = self::getContainer();

        self::assertFalse($container->has('sylius.encrypter'), 'This environment must run with Sylius payment encryption disabled.');

        $encrypter = $container->get('jpmmartin_carrier.encrypter');
        self::assertInstanceOf(EncrypterInterface::class, $encrypter);

        self::assertSame('s3cr3t', $encrypter->decrypt($encrypter->encrypt('s3cr3t')));
    }
}
