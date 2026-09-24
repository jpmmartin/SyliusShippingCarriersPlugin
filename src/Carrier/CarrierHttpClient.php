<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The PSR-18 client the UPS SDK calls through, over Symfony's. It returns 4xx and 5xx responses instead of
 * throwing, so the SDK sees them, and reads each answer whole before returning it.
 *
 * Symfony's own Psr18Client streams the body after the headers arrive, and a timeout in the middle of it then
 * leaves the SDK an empty body that it reports as an unreadable answer. Read here, the same timeout is a
 * network error, and the carrier is reported as unavailable.
 *
 * @internal
 */
final readonly class CarrierHttpClient implements ClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private float $timeout,
        private ?CarrierCallScope $scope = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            $body = $request->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }

            // Per request, because the channel the call is made for says how long it may take, whether the carrier
            // stays silent or keeps sending slowly.
            $timeout = $this->scope?->carrierTimeout() ?? $this->timeout;
            $response = $this->httpClient->request($request->getMethod(), (string) $request->getUri(), [
                'headers' => $request->getHeaders(),
                'body' => $body->getContents(),
                'timeout' => $timeout,
                'max_duration' => $timeout,
            ]);

            $content = $response->getContent(false);
            $psrResponse = $this->responseFactory->createResponse($response->getStatusCode());
            foreach ($response->getHeaders(false) as $name => $values) {
                foreach ($values as $value) {
                    try {
                        $psrResponse = $psrResponse->withAddedHeader($name, $value);
                    } catch (\InvalidArgumentException) {
                        // A header PSR-7 refuses carries nothing the SDK reads.
                    }
                }
            }

            return $psrResponse->withBody($this->streamFactory->createStream($content));
        } catch (TransportExceptionInterface $exception) {
            throw new CarrierNetworkException($exception, $request);
        }
    }
}
