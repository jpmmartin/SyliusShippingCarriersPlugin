<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex;

use GuzzleHttp\RequestOptions;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Psr\Cache\CacheItemPoolInterface;
use ShipStream\FedEx\Enums\Endpoint;
use ShipStream\FedEx\FedEx;
use Symfony\Component\Lock\LockFactory;

/**
 * Builds the FedEx SDK connector for the stored credentials. The access token is shared by every process and
 * obtained under a lock (D-25), and no request waits longer than the carrier timeout (D-27).
 */
final class FedexConnectorFactory
{
    /** @var array<string, FedEx> */
    private array $connectors = [];

    public function __construct(
        CacheItemPoolInterface $accessTokenPool,
        EncrypterInterface $encrypter,
        private readonly LockFactory $lockFactory,
        private readonly float $timeout,
    ) {
        FedexTokenCache::configure($accessTokenPool, $encrypter);
    }

    public function create(CarrierCredentialsInterface $credentials): FedEx
    {
        $values = $credentials->getCredentials();
        $clientId = $values[CarrierCredentialsInterface::CLIENT_ID] ?? '';
        $clientSecret = $values[CarrierCredentialsInterface::CLIENT_SECRET] ?? '';
        $endpoint = CarrierCredentialsInterface::ENVIRONMENT_SANDBOX === $credentials->getEnvironment() ? Endpoint::SANDBOX : Endpoint::PROD;

        $key = hash('sha256', implode("\0", [$clientId, $clientSecret, $endpoint->value]));

        if (!isset($this->connectors[$key])) {
            $connector = new FedEx(
                clientId: $clientId,
                clientSecret: $clientSecret,
                endpoint: $endpoint,
                tokenCache: new FedexTokenCache(),
                tokenLock: new FedexTokenLock($this->lockFactory),
            );
            // Saloon passes the connector's config to Guzzle with every request, the token request included (D-27).
            $connector->config()->merge([
                RequestOptions::CONNECT_TIMEOUT => $this->timeout,
                RequestOptions::TIMEOUT => $this->timeout,
            ]);

            $this->connectors[$key] = $connector;
        }

        return $this->connectors[$key];
    }
}
