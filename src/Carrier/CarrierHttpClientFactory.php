<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The PSR-18 client the carrier SDKs call through. Symfony's returns 4xx and 5xx responses instead of throwing,
 * so the SDKs see them, and gives up on a request once the carrier timeout has passed, whether the carrier
 * stays silent or keeps sending slowly (D-27).
 */
final class CarrierHttpClientFactory
{
    public static function create(HttpClientInterface $httpClient, float $timeout): ClientInterface
    {
        return new Psr18Client($httpClient->withOptions([
            'timeout' => $timeout,
            'max_duration' => $timeout,
        ]));
    }
}
