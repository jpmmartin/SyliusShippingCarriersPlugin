<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex;

use GuzzleHttp\RequestOptions;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierCallScope;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Psr\Cache\CacheItemPoolInterface;
use ShipStream\FedEx\Enums\Endpoint;
use ShipStream\FedEx\FedEx;
use Symfony\Component\Lock\LockFactory;

/**
 * Builds the FedEx SDK connector for the stored credentials. The access token is shared by every process and
 * obtained under a lock, and no request waits longer than the carrier timeout.
 *
 * @internal
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
        private readonly ?CarrierCallScope $scope = null,
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

        $connector = $this->connectors[$key] ??= new FedEx(
            clientId: $clientId,
            clientSecret: $clientSecret,
            endpoint: $endpoint,
            tokenCache: new FedexTokenCache(),
            tokenLock: new FedexTokenLock($this->lockFactory),
        );

        // Set on every call, the connector kept or not: the channel the call is made for says how long it may take.
        // Saloon passes the connector's config to Guzzle with every request, the token request included.
        $timeout = $this->scope?->carrierTimeout() ?? $this->timeout;
        $connector->config()->merge([
            RequestOptions::CONNECT_TIMEOUT => $timeout,
            RequestOptions::TIMEOUT => $timeout,
        ]);

        return $connector;
    }
}
