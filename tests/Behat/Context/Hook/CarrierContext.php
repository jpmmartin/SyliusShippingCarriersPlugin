<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Hook;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use ParagonIE\Halite\KeyFactory;
use Psr\Cache\CacheItemPoolInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;

/**
 * Every scenario starts with carriers that have not been told how to answer, no rates stored from an earlier
 * scenario, and a fresh key to encrypt the carrier credentials it stores.
 */
final class CarrierContext implements Context
{
    private const KEY_PATH_VARIABLE = 'JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH';

    private ?string $keyPath = null;

    public function __construct(
        private readonly FakeCarrierState $fakeCarrierState,
        private readonly CacheItemPoolInterface $rateCache,
    ) {
    }

    #[BeforeScenario]
    public function prepareCarriers(): void
    {
        $this->fakeCarrierState->reset();
        // The rate cache lives on the filesystem and outlives the database purge between scenarios.
        $this->rateCache->clear();

        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_behat_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);
        $_SERVER[self::KEY_PATH_VARIABLE] = $this->keyPath;
        $_ENV[self::KEY_PATH_VARIABLE] = $this->keyPath;
    }

    #[AfterScenario]
    public function cleanUpCarriers(): void
    {
        $this->fakeCarrierState->reset();

        unset($_SERVER[self::KEY_PATH_VARIABLE], $_ENV[self::KEY_PATH_VARIABLE]);
        if (null !== $this->keyPath && is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
        $this->keyPath = null;
    }
}
