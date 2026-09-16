<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier\Ups;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsAccessTokenCache;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsClientFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Against the fixtures in `fixtures/`, written from the SDK's schemas and not checked against UPS yet.
 */
final class UpsCarrierTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /** @var array<string, string> */
    private array $credentials = [
        CarrierCredentialsInterface::CLIENT_ID => 'ups-client-id',
        CarrierCredentialsInterface::CLIENT_SECRET => 'ups-client-secret',
        CarrierCredentialsInterface::ACCOUNT_NUMBER => 'A1B2C3',
    ];

    private string $environment = CarrierCredentialsInterface::ENVIRONMENT_SANDBOX;

    private string $keyPath;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_ups_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    public function testEveryServiceIsRatedInASingleCall(): void
    {
        $rates = $this->carrier($this->json('rate-shop.json'))->rate($this->request());

        self::assertCount(1, $this->rateRequests());
        self::assertStringEndsWith('/rating/v2403/Shop', $this->rateRequests()[0]['url']);
        self::assertEquals([new Rate('03', 1125, 'USD'), new Rate('02', 4210, 'USD')], $rates->all());
    }

    /**
     * D-23: the negotiated charge is what UPS bills; without one, the published charge applies.
     */
    public function testTheNegotiatedChargeIsUsedWhenThereIsOneAndThePublishedOneOtherwise(): void
    {
        $rates = $this->carrier($this->json('rate-shop.json'))->rate($this->request());

        self::assertSame(1125, $rates->get('03')?->amount, 'Negotiated 11.25 instead of published 15.40.');
        self::assertSame(4210, $rates->get('02')?->amount, 'No negotiated charge, published 42.10.');
    }

    public function testASingleServiceSentAsAnObjectIsRead(): void
    {
        $rates = $this->carrier($this->json('rate-single-service.json'))->rate($this->request());

        self::assertEquals([new Rate('03', 1540, 'USD')], $rates->all());
    }

    public function testWithAnAccountNumberTheNegotiatedRatesAreRequested(): void
    {
        $this->carrier($this->json('rate-shop.json'))->rate($this->request());

        $shipment = $this->sentShipment();
        self::assertSame('A1B2C3', $shipment['Shipper']['ShipperNumber'] ?? null);
        self::assertArrayHasKey('NegotiatedRatesIndicator', $shipment['ShipmentRatingOptions'] ?? []);
    }

    public function testWithoutAnAccountNumberOnlyThePublishedRatesAreRequested(): void
    {
        unset($this->credentials[CarrierCredentialsInterface::ACCOUNT_NUMBER]);

        $this->carrier($this->json('rate-shop.json'))->rate($this->request());

        $shipment = $this->sentShipment();
        self::assertArrayNotHasKey('ShipperNumber', $shipment['Shipper']);
        self::assertArrayNotHasKey('ShipmentRatingOptions', $shipment);
    }

    public function testTheRequestCarriesTheAddressesAndThePackages(): void
    {
        $this->carrier($this->json('rate-shop.json'))->rate($this->request());

        $shipment = $this->sentShipment();
        self::assertSame(
            ['AddressLine' => ['1 Main St'], 'City' => 'Chicago', 'StateProvinceCode' => 'IL', 'PostalCode' => '60601', 'CountryCode' => 'US'],
            $shipment['ShipFrom']['Address'],
        );
        self::assertSame(
            ['AddressLine' => ['500 Pine St'], 'City' => 'Seattle', 'StateProvinceCode' => 'WA', 'PostalCode' => '98101', 'CountryCode' => 'US'],
            $shipment['ShipTo']['Address'],
        );
        self::assertSame($shipment['ShipFrom']['Address'], $shipment['Shipper']['Address'] ?? null);
        self::assertSame([
            [
                'PackagingType' => ['Code' => '02'],
                'Dimensions' => ['UnitOfMeasurement' => ['Code' => 'IN'], 'Length' => '14', 'Width' => '11', 'Height' => '9'],
                'PackageWeight' => ['UnitOfMeasurement' => ['Code' => 'LBS'], 'Weight' => '5.6'],
            ],
        ], $shipment['Package']);
    }

    /**
     * D-24: UPS takes whole measures, longest first, and the weight to a tenth, both rounded up.
     */
    public function testPackagesInCentimetresAndKilogramsAreSentRoundedUpLongestFirst(): void
    {
        $package = new Package('Medium', 20.0, 45.2, 30.0, 'cm', 2.01, 'kg', []);

        $this->carrier($this->json('rate-shop.json'))->rate($this->request($package));

        self::assertSame([
            'PackagingType' => ['Code' => '02'],
            'Dimensions' => ['UnitOfMeasurement' => ['Code' => 'CM'], 'Length' => '46', 'Width' => '30', 'Height' => '20'],
            'PackageWeight' => ['UnitOfMeasurement' => ['Code' => 'KGS'], 'Weight' => '2.1'],
        ], $this->sentShipment()['Package'][0]);
    }

    public function testTheSandboxAndProductionHostsFollowTheStoredEnvironment(): void
    {
        $this->carrier($this->json('rate-shop.json'))->rate($this->request());
        self::assertStringStartsWith('https://wwwcie.ups.com/', $this->rateRequests()[0]['url']);

        $this->requests = [];
        $this->environment = CarrierCredentialsInterface::ENVIRONMENT_PRODUCTION;
        $this->carrier($this->json('rate-shop.json'))->rate($this->request());
        self::assertStringStartsWith('https://onlinetools.ups.com/', $this->rateRequests()[0]['url']);
    }

    public function testWithoutStoredCredentialsUpsIsNotCalled(): void
    {
        $carrier = new UpsCarrier(
            new CredentialsProvider($this->repository(null)),
            $this->clientFactory(new MockHttpClient(fn () => self::fail('UPS was called.'))),
        );

        $this->expectException(CarrierCredentialsException::class);
        $carrier->rate($this->request());
    }

    public function testARejectedRequestKeepsWhatUpsSaid(): void
    {
        $carrier = $this->carrier(new MockResponse($this->fixture('error.json'), [
            'http_code' => 400,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $this->expectException(CarrierRejectedRequestException::class);
        $this->expectExceptionMessage('111210: The requested service is unavailable between the selected locations.');
        $carrier->rate($this->request());
    }

    private function carrier(MockResponse $rateResponse): UpsCarrier
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_UPS);
        $credentials->setEnvironment($this->environment);
        $credentials->setCredentials($this->credentials);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($rateResponse): MockResponse {
            $body = $options['body'] ?? '';
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => \is_string($body) ? $body : ''];

            return str_contains($url, '/security/v1/oauth/token') ? $this->token() : $rateResponse;
        });

        return new UpsCarrier(new CredentialsProvider($this->repository($credentials)), $this->clientFactory($httpClient));
    }

    private function clientFactory(MockHttpClient $httpClient): UpsClientFactory
    {
        return new UpsClientFactory(
            new Psr18Client($httpClient),
            new UpsAccessTokenCache(new ArrayAdapter(), new Encrypter($this->keyPath)),
            new LockFactory(new InMemoryStore()),
        );
    }

    /**
     * @return RepositoryInterface<CarrierCredentialsInterface>
     */
    private function repository(?CarrierCredentialsInterface $credentials): RepositoryInterface
    {
        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($credentials);

        return $repository;
    }

    private function request(?Package $package = null): RateRequest
    {
        return new RateRequest(
            new Address('US', '60601', 'Chicago', '1 Main St', 'IL'),
            new Address('US', '98101', 'Seattle', '500 Pine St', 'WA'),
            [$package ?? new Package('Medium', 11.0, 13.2, 9.0, 'in', 5.55, 'lb', [])],
        );
    }

    /**
     * @return list<array{method: string, url: string, body: string}>
     */
    private function rateRequests(): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => str_contains($request['url'], '/rating/')));
    }

    /**
     * @return array{
     *     Shipper: array<string, mixed>,
     *     ShipFrom: array{Address: array<string, mixed>},
     *     ShipTo: array{Address: array<string, mixed>},
     *     Package: list<array<string, mixed>>,
     *     ShipmentRatingOptions?: array<string, mixed>,
     * }
     */
    private function sentShipment(): array
    {
        /** @var array{RateRequest: array{Shipment: array{Shipper: array<string, mixed>, ShipFrom: array{Address: array<string, mixed>}, ShipTo: array{Address: array<string, mixed>}, Package: list<array<string, mixed>>, ShipmentRatingOptions?: array<string, mixed>}}} $body */
        $body = json_decode($this->rateRequests()[0]['body'], true, flags: \JSON_THROW_ON_ERROR);

        return $body['RateRequest']['Shipment'];
    }

    private function token(): MockResponse
    {
        return $this->json('token.json', ['%issued_at%' => (string) (time() * 1000)]);
    }

    /**
     * @param array<string, string> $replacements
     */
    private function json(string $fixture, array $replacements = []): MockResponse
    {
        return new MockResponse(strtr($this->fixture($fixture), $replacements), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }
}
