<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier\Fedex;

use GuzzleHttp\RequestOptions;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierCallScope;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexConnectorFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsFactory;

final class FedexConnectorFactoryTest extends TestCase
{
    private string $keyPath;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_fedex_factory_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    /**
     * The connector is kept for the credentials, and still every call is made with the timeout of the channel it is
     * made for: Saloon hands the connector's config to Guzzle with every request.
     */
    public function testAKeptConnectorTakesTheTimeoutOfEachCall(): void
    {
        $scope = new CarrierCallScope();
        $factory = new FedexConnectorFactory(new ArrayAdapter(), new Encrypter($this->keyPath), new LockFactory(new InMemoryStore()), 10.0, $scope);
        $credentials = $this->credentials();

        $forAChannel = $scope->within(CarrierSettingsFactory::provider(carrierTimeout: 2.5)->defaults(), static fn () => $factory->create($credentials));
        self::assertSame(2.5, $forAChannel->config()->get(RequestOptions::TIMEOUT));
        self::assertSame(2.5, $forAChannel->config()->get(RequestOptions::CONNECT_TIMEOUT));

        $forNone = $factory->create($credentials);
        self::assertSame($forAChannel, $forNone, 'The connector is the one kept for these credentials.');
        self::assertSame(10.0, $forNone->config()->get(RequestOptions::TIMEOUT));
    }

    private function credentials(): CarrierCredentialsInterface
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_FEDEX);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => 'client-id',
            CarrierCredentialsInterface::CLIENT_SECRET => 'client-secret',
        ]);

        return $credentials;
    }
}
