<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierHttpClientFactory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CarrierHttpClientTest extends TestCase
{
    public function testAnAnswerIsReturnedWholeWithItsStatusAndHeadersEvenForAServerError(): void
    {
        $response = $this->send(new MockResponse('{"errors":[]}', [
            'http_code' => 500,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        self::assertSame('{"errors":[]}', (string) $response->getBody());
    }

    public function testTheRequestIsSentWithItsMethodHeadersAndBody(): void
    {
        $sent = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = [$method, $url, $options['body'] ?? null, $options['normalized_headers']['content-type'][0] ?? null];

            return new MockResponse('{}');
        });

        CarrierHttpClientFactory::create($httpClient, 10.0)->sendRequest(
            new Request('POST', 'https://wwwcie.ups.com/api/rating/v2403/Shop', ['Content-Type' => 'application/json'], '{"RateRequest":{}}'),
        );

        self::assertSame(['POST', 'https://wwwcie.ups.com/api/rating/v2403/Shop', '{"RateRequest":{}}', 'Content-Type: application/json'], $sent);
    }

    public function testACarrierThatDoesNotAnswerIsANetworkError(): void
    {
        $this->expectException(NetworkExceptionInterface::class);

        $this->send(new MockResponse('', ['error' => 'Idle timeout reached for "https://wwwcie.ups.com/api/rating/v2403/Shop".']));
    }

    /**
     * D-27: a timeout once the headers arrived must not leave an empty body the SDK takes for an unreadable answer.
     */
    public function testACarrierThatStopsInTheMiddleOfItsAnswerIsANetworkError(): void
    {
        $this->expectException(NetworkExceptionInterface::class);

        $this->send(new MockResponse((static function (): \Generator {
            yield '{"RateResponse": ';
            // An empty chunk is how Symfony's mock simulates a carrier that stops sending.
            yield '';
        })(), ['response_headers' => ['content-type' => 'application/json']]));
    }

    private function send(MockResponse $response): \Psr\Http\Message\ResponseInterface
    {
        return CarrierHttpClientFactory::create(new MockHttpClient($response), 10.0)
            ->sendRequest(new Request('POST', 'https://wwwcie.ups.com/api/rating/v2403/Shop'))
        ;
    }
}
