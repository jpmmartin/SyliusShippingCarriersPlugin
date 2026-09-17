<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierHttpClientFactory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CarrierHttpClientFactoryTest extends TestCase
{
    /**
     * The client UPS is called through gives up once the carrier timeout has passed.
     */
    public function testEveryRequestCarriesTheCarrierTimeout(): void
    {
        $options = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
            $options = $requestOptions;

            return new MockResponse('{}');
        });

        CarrierHttpClientFactory::create($httpClient, 4.5)->sendRequest(new Request('POST', 'https://wwwcie.ups.com/api/rating/v2403/Shop'));

        self::assertSame([4.5, 4.5], [$options['timeout'] ?? null, $options['max_duration'] ?? null]);
    }
}
