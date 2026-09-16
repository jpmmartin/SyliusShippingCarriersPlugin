<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Psr\Http\Client\ClientInterface;
use ShipStream\Ups\Client;
use ShipStream\Ups\ClientFactory;
use ShipStream\Ups\Config;

/**
 * Builds the UPS SDK client for the stored credentials, over a PSR-18 client that does not throw on 4xx or
 * 5xx responses, so the SDK sees them and raises its own exceptions.
 */
final class UpsClientFactory
{
    /**
     * One client per set of credentials, so the access token it obtained serves the next calls of the same
     * process.
     *
     * @var array<string, Client>
     */
    private array $clients = [];

    public function __construct(
        private readonly ClientInterface $httpClient,
    ) {
    }

    public function create(CarrierCredentialsInterface $credentials): Client
    {
        $values = $credentials->getCredentials();
        $clientId = $values[CarrierCredentialsInterface::CLIENT_ID] ?? '';
        $clientSecret = $values[CarrierCredentialsInterface::CLIENT_SECRET] ?? '';
        $sandbox = CarrierCredentialsInterface::ENVIRONMENT_SANDBOX === $credentials->getEnvironment();

        $key = hash('sha256', implode("\0", [$clientId, $clientSecret, $sandbox ? 'sandbox' : 'production']));

        return $this->clients[$key] ??= ClientFactory::create(new Config([
            'use_testing_environment' => $sandbox,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]), null, $this->httpClient);
    }
}
