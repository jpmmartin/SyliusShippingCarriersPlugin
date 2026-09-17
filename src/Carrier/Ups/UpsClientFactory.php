<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Psr\Http\Client\ClientInterface;
use ShipStream\Ups\Authentication\AccessTokenCache;
use ShipStream\Ups\Client;
use ShipStream\Ups\ClientFactory;
use ShipStream\Ups\Config;
use Symfony\Component\Lock\LockFactory;

/**
 * Builds the UPS SDK client for the stored credentials, over a PSR-18 client that does not throw on 4xx or
 * 5xx responses, so the SDK sees them and raises its own exceptions. The access token is shared by every
 * process and renewed under a lock.
 */
final class UpsClientFactory
{
    /** @var array<string, Client> */
    private array $clients = [];

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly AccessTokenCache $accessTokenCache,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function create(CarrierCredentialsInterface $credentials): Client
    {
        $values = $credentials->getCredentials();
        $clientId = $values[CarrierCredentialsInterface::CLIENT_ID] ?? '';
        $clientSecret = $values[CarrierCredentialsInterface::CLIENT_SECRET] ?? '';
        $sandbox = CarrierCredentialsInterface::ENVIRONMENT_SANDBOX === $credentials->getEnvironment();

        $key = hash('sha256', implode("\0", [$clientId, $clientSecret, $sandbox ? 'sandbox' : 'production']));

        return $this->clients[$key] ??= ClientFactory::create(
            new Config([
                'use_testing_environment' => $sandbox,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]),
            $this->accessTokenCache,
            $this->httpClient,
            new UpsAccessTokenLock($this->lockFactory, $clientId),
        );
    }
}
