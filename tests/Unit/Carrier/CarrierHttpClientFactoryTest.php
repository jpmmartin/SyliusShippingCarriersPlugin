<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierCallScope;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierHttpClientFactory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsFactory;

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

    /**
     * A request made for a channel gives up when that channel says; the next one, made for none, when the
     * configuration says.
     */
    public function testARequestMadeForAChannelCarriesThatChannelsTimeout(): void
    {
        $timeouts = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $requestOptions) use (&$timeouts): MockResponse {
            $timeouts[] = [$requestOptions['timeout'] ?? null, $requestOptions['max_duration'] ?? null];

            return new MockResponse('{}');
        });
        $scope = new CarrierCallScope();
        $client = CarrierHttpClientFactory::create($httpClient, 10.0, $scope);
        $request = new Request('POST', 'https://wwwcie.ups.com/api/rating/v2403/Shop');

        $scope->within(CarrierSettingsFactory::provider(carrierTimeout: 2.5)->defaults(), static fn () => $client->sendRequest($request));
        $client->sendRequest($request);

        self::assertSame([[2.5, 2.5], [10.0, 10.0]], $timeouts);
    }
}
