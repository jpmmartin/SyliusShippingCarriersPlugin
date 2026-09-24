<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The PSR-18 client the carrier SDKs call through. It gives up on a request once the carrier timeout has
 * passed, whether the carrier stays silent or keeps sending slowly.
 *
 * @internal
 */
final class CarrierHttpClientFactory
{
    /**
     * @param float $timeout The configuration's, which a call made within a channel's settings replaces
     */
    public static function create(HttpClientInterface $httpClient, float $timeout, ?CarrierCallScope $scope = null): ClientInterface
    {
        $psr17Factory = new Psr17Factory();

        return new CarrierHttpClient($httpClient, $psr17Factory, $psr17Factory, $timeout, $scope);
    }
}
