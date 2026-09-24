<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier;

use GuzzleHttp\Exception\ConnectException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierHttpClientFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexConnectorFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexLabelCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelCarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentResult;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\VoidResult;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsAccessTokenCache;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsClientFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsLabelCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateSet;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse as SaloonMockResponse;
use Saloon\Http\PendingRequest;
use Saloon\RateLimitPlugin\Stores\MemoryStore;
use ShipStream\FedEx\Api\AuthorizationV1\Requests\ApiAuthorization;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Requests\RateAndTransitTimes;
use ShipStream\FedEx\Api\ShipV1\Requests\CancelShipment;
use ShipStream\FedEx\Api\ShipV1\Requests\CreateShipment;
use ShipStream\FedEx\Api\TrackV1\Requests\TrackByTrackingNumber;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Whatever a carrier does, what leaves an adapter is a CarrierException and never an exception of an
 * SDK, of the HTTP client or of the JSON decoding. Each failure is forced where it really happens: in the HTTP
 * layer each SDK sends through.
 */
final class NoSdkExceptionEscapesTest extends TestCase
{
    private const JSON = ['content-type' => 'application/json'];

    private string $keyPath;

    protected function setUp(): void
    {
        // The FedEx SDK keeps its rate limits in a static store, shared by every test of the process.
        MemoryStore::clear();
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_sdk_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    /**
     * @param class-string<CarrierException> $expected
     */
    #[DataProvider('upsFailures')]
    public function testNoUpsFailureLeavesAsAnSdkException(string $failure, string $expected): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($failure): MockResponse {
            if (str_contains($url, '/security/v1/oauth/token')) {
                return 'invalid credentials' === $failure
                    ? new MockResponse('{"response":{"errors":[{"code":"250003","message":"Invalid Access Token"}]}}', ['http_code' => 401, 'response_headers' => self::JSON])
                    : new MockResponse($this->upsToken(), ['http_code' => 200, 'response_headers' => self::JSON]);
            }

            return match ($failure) {
                'timeout before answering' => new MockResponse('', ['error' => sprintf('Idle timeout reached for "%s".', $url)]),
                // An empty chunk is how Symfony's mock simulates a carrier that stops sending.
                'timeout in the middle of the answer' => new MockResponse((static function (): \Generator {
                    yield '{"RateResponse": {"RatedShipment": [';
                    yield '';
                })(), ['http_code' => 200, 'response_headers' => self::JSON]),
                'server error' => new MockResponse('<html>Internal Server Error</html>', ['http_code' => 500, 'response_headers' => ['content-type' => 'text/html']]),
                'unreadable JSON' => new MockResponse('{"RateResponse": {"RatedShipment": [', ['http_code' => 200, 'response_headers' => self::JSON]),
                default => self::fail(sprintf('No rate request was expected with %s.', $failure)),
            };
        });

        $credentialsProvider = $this->credentialsProvider(CarrierCredentialsInterface::CARRIER_UPS);
        $clientFactory = new UpsClientFactory(
            CarrierHttpClientFactory::create($httpClient, 10.0),
            new UpsAccessTokenCache(new ArrayAdapter(), new Encrypter($this->keyPath)),
            new LockFactory(new InMemoryStore()),
        );

        $this->assertFailsWith($expected, new UpsCarrier($credentialsProvider, $clientFactory));
        $this->assertLabelsFailWith($expected, new UpsLabelCarrier($credentialsProvider, $clientFactory), recovers: true);
    }

    /**
     * @return iterable<string, array{string, class-string<CarrierException>}>
     */
    public static function upsFailures(): iterable
    {
        yield 'timeout before answering' => ['timeout before answering', CarrierUnavailableException::class];
        yield 'timeout in the middle of the answer' => ['timeout in the middle of the answer', CarrierUnavailableException::class];
        yield 'server error' => ['server error', CarrierUnavailableException::class];
        yield 'unreadable JSON' => ['unreadable JSON', UnexpectedCarrierResponseException::class];
        yield 'invalid credentials' => ['invalid credentials', CarrierCredentialsException::class];
    }

    /**
     * @param class-string<CarrierException> $expected
     */
    #[DataProvider('fedexFailures')]
    public function testNoFedexFailureLeavesAsAnSdkException(string $failure, string $expected): void
    {
        MockClient::global([
            ApiAuthorization::class => 'invalid credentials' === $failure
                ? new SaloonMockResponse('{"errors":[{"code":"NOT.AUTHORIZED.ERROR","message":"The given client credentials were not valid."}]}', 401, self::JSON)
                : new SaloonMockResponse('{"access_token":"fedex-access-token","token_type":"bearer","expires_in":3599,"scope":"CXS"}', 200, self::JSON),
            TrackByTrackingNumber::class => match ($failure) {
                'timeout' => (new SaloonMockResponse())->throw(static fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(
                    new ConnectException('cURL error 28: Operation timed out after 10000 milliseconds', $pendingRequest->createPsrRequest()),
                    $pendingRequest,
                )),
                'server error' => new SaloonMockResponse('{"errors":[{"code":"INTERNAL.SERVER.ERROR","message":"We encountered an unexpected error."}]}', 500, self::JSON),
                'unreadable JSON' => new SaloonMockResponse('{"output": {"completeTrackResults": [', 200, self::JSON),
                default => static fn (): never => self::fail(sprintf('No tracking request was expected with %s.', $failure)),
            },
            CreateShipment::class => match ($failure) {
                'timeout' => (new SaloonMockResponse())->throw(static fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(
                    new ConnectException('cURL error 28: Operation timed out after 10000 milliseconds', $pendingRequest->createPsrRequest()),
                    $pendingRequest,
                )),
                'server error' => new SaloonMockResponse('{"errors":[{"code":"INTERNAL.SERVER.ERROR","message":"We encountered an unexpected error."}]}', 500, self::JSON),
                'unreadable JSON' => new SaloonMockResponse('{"output": {"transactionShipments": [', 200, self::JSON),
                default => static fn (): never => self::fail(sprintf('No shipment request was expected with %s.', $failure)),
            },
            CancelShipment::class => match ($failure) {
                'timeout' => (new SaloonMockResponse())->throw(static fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(
                    new ConnectException('cURL error 28: Operation timed out after 10000 milliseconds', $pendingRequest->createPsrRequest()),
                    $pendingRequest,
                )),
                'server error' => new SaloonMockResponse('{"errors":[{"code":"INTERNAL.SERVER.ERROR","message":"We encountered an unexpected error."}]}', 500, self::JSON),
                'unreadable JSON' => new SaloonMockResponse('{"output": {"cancelledShipment": ', 200, self::JSON),
                default => static fn (): never => self::fail(sprintf('No cancellation was expected with %s.', $failure)),
            },
            RateAndTransitTimes::class => match ($failure) {
                // What Guzzle throws when a request times out.
                'timeout' => (new SaloonMockResponse())->throw(static fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(
                    new ConnectException('cURL error 28: Operation timed out after 10000 milliseconds', $pendingRequest->createPsrRequest()),
                    $pendingRequest,
                )),
                'server error' => new SaloonMockResponse('{"errors":[{"code":"INTERNAL.SERVER.ERROR","message":"We encountered an unexpected error."}]}', 500, self::JSON),
                'unreadable JSON' => new SaloonMockResponse('{"output": {"rateReplyDetails": [', 200, self::JSON),
                default => static fn (): never => self::fail(sprintf('No rate request was expected with %s.', $failure)),
            },
        ]);

        $credentialsProvider = $this->credentialsProvider(CarrierCredentialsInterface::CARRIER_FEDEX);
        $connectorFactory = new FedexConnectorFactory(new ArrayAdapter(), new Encrypter($this->keyPath), new LockFactory(new InMemoryStore()), 10.0);

        $this->assertFailsWith($expected, new FedexCarrier($credentialsProvider, $connectorFactory));
        // FedEx never asks anybody whether it issued a shipment: it has no operation that answers that.
        $this->assertLabelsFailWith($expected, new FedexLabelCarrier($credentialsProvider, $connectorFactory), recovers: false);
    }

    /**
     * @return iterable<string, array{string, class-string<CarrierException>}>
     */
    public static function fedexFailures(): iterable
    {
        yield 'timeout' => ['timeout', CarrierUnavailableException::class];
        yield 'server error' => ['server error', CarrierUnavailableException::class];
        yield 'unreadable JSON' => ['unreadable JSON', UnexpectedCarrierResponseException::class];
        yield 'invalid credentials' => ['invalid credentials', CarrierCredentialsException::class];
    }

    /**
     * @param class-string<CarrierException> $expected
     */
    private function assertFailsWith(string $expected, CarrierInterface $carrier): void
    {
        $this->assertOperationFailsWith($expected, fn (): RateSet => $carrier->rate($this->request()), 'Rating');
        $this->assertOperationFailsWith($expected, fn (): TrackingInfo => $carrier->track('1Z999AA10123456784'), 'Tracking');
    }

    /**
     * Issuing and cancelling a label reach the carrier the same way rating does, and a failure of the carrier
     * must not leave the adapter as whatever its SDK happens to throw.
     *
     * @param class-string<CarrierException> $expected
     * @param bool $recovers Whether this carrier can be asked if it issued a shipment. FedEx cannot, so it
     *                       answers null without calling anybody and has nothing to fail at
     */
    private function assertLabelsFailWith(string $expected, LabelCarrierInterface $carrier, bool $recovers): void
    {
        $this->assertOperationFailsWith($expected, fn (): ShipmentResult => $carrier->ship($this->shipmentRequest()), 'Issuing');
        $this->assertOperationFailsWith($expected, fn (): VoidResult => $carrier->void('1Z999AA10123456784'), 'Cancelling');

        if ($recovers) {
            $this->assertOperationFailsWith($expected, fn (): ?ShipmentResult => $carrier->recover('1Z999AA10123456784'), 'Recovering');

            return;
        }

        self::assertNull($carrier->recover('1Z999AA10123456784'));
    }

    private function shipmentRequest(): ShipmentRequest
    {
        return new ShipmentRequest(
            new Address('US', '60601', 'Chicago', '1 Main St', 'IL', false, 'The store', 'Ada Lovelace', '13057800955'),
            new Address('US', '98101', 'Seattle', '500 Pine St', 'WA', true, null, 'Grace Hopper', '12065550100'),
            '03',
            [new ShipmentPackage(new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', []))],
            'PDF',
            'the shop reference',
        );
    }

    /**
     * @param class-string<CarrierException> $expected
     */
    private function assertOperationFailsWith(string $expected, \Closure $operation, string $what): void
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($expected, $exception, sprintf('%s left the adapter while %s: %s', $exception::class, strtolower($what), $exception->getMessage()));

            return;
        }

        self::fail(sprintf('%s did not report the carrier failure.', $what));
    }

    private function credentialsProvider(string $carrier): CredentialsProvider
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier($carrier);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_DROP_OFF);
        $credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => $carrier . '-client-id',
            CarrierCredentialsInterface::CLIENT_SECRET => $carrier . '-client-secret',
            CarrierCredentialsInterface::ACCOUNT_NUMBER => '740561073',
        ]);

        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($credentials);

        return new CredentialsProvider($repository);
    }

    private function request(): RateRequest
    {
        return new RateRequest(
            new Address('US', '60601', 'Chicago', '1 Main St', 'IL'),
            new Address('US', '98101', 'Seattle', '500 Pine St', 'WA'),
            [new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', [])],
        );
    }

    private function upsToken(): string
    {
        return sprintf(
            '{"token_type":"Bearer","issued_at":"%d","client_id":"ups-client-id","access_token":"ups-access-token","scope":"","expires_in":"14399","refresh_count":"0","status":"approved"}',
            time() * 1000,
        );
    }
}
