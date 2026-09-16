<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier\Fedex;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexConnectorFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use ShipStream\FedEx\Api\AuthorizationV1\Requests\ApiAuthorization;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Requests\RateAndTransitTimes;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Against the fixtures in `fixtures/`, written from the SDK's schemas and not checked against FedEx yet.
 * Each FedexCarrier built here stands for a process; the cache pool and the lock store are what processes
 * share.
 */
final class FedexCarrierTest extends TestCase
{
    /** @var list<array{url: string, body: array<array-key, mixed>}> */
    private array $rateRequests = [];

    private int $tokenRequests = 0;

    /** @var array<string, string> */
    private array $credentials = [
        CarrierCredentialsInterface::CLIENT_ID => 'fedex-client-id',
        CarrierCredentialsInterface::CLIENT_SECRET => 'fedex-client-secret',
        CarrierCredentialsInterface::ACCOUNT_NUMBER => '740561073',
    ];

    private string $environment = CarrierCredentialsInterface::ENVIRONMENT_SANDBOX;

    private string $pickupType = CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED;

    private float $timeout = 10.0;

    /** @var list<array{mixed, mixed}> The connect and request timeouts of every request FedEx received, the token request included */
    private array $timeouts = [];

    private ArrayAdapter $pool;

    private LockFactory $lockFactory;

    private string $keyPath;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_fedex_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);

        $this->mockFedex(new MockResponse($this->fixture('rate-quote.json'), 200, ['Content-Type' => 'application/json']));
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    public function testEveryServiceIsRatedInASingleCall(): void
    {
        $rates = $this->process()->rate($this->request());

        self::assertCount(1, $this->rateRequests);
        self::assertEquals([new Rate('FEDEX_GROUND', 1842, 'USD'), new Rate('PRIORITY_OVERNIGHT', 9635, 'USD')], $rates->all());
    }

    /**
     * D-23: the account rate is what FedEx bills; without one, the rate FedEx gives applies.
     */
    public function testTheAccountRateIsUsedWhenThereIsOne(): void
    {
        $rates = $this->process()->rate($this->request());

        self::assertSame(1842, $rates->get('FEDEX_GROUND')?->amount, 'Account 18.42 instead of list 24.10.');
        self::assertSame(9635, $rates->get('PRIORITY_OVERNIGHT')?->amount, 'Only a list rate, 96.35.');
    }

    public function testTheRequestAsksForTheAccountRatesOfTheAddressesAndPackages(): void
    {
        $this->process()->rate($this->request());

        self::assertSame([
            'accountNumber' => ['value' => '740561073'],
            'requestedShipment' => [
                'shipper' => ['address' => ['city' => 'Chicago', 'stateOrProvinceCode' => 'IL', 'postalCode' => '60601', 'countryCode' => 'US']],
                'recipient' => ['address' => ['city' => 'Seattle', 'stateOrProvinceCode' => 'WA', 'postalCode' => '98101', 'countryCode' => 'US']],
                'pickupType' => 'USE_SCHEDULED_PICKUP',
                'requestedPackageLineItems' => [
                    [
                        'weight' => ['units' => 'LB', 'value' => 5.6],
                        'dimensions' => ['length' => 14, 'width' => 11, 'height' => 9, 'units' => 'IN'],
                    ],
                ],
                'rateRequestType' => ['ACCOUNT'],
                'packagingType' => 'YOUR_PACKAGING',
            ],
        ], $this->rateRequests[0]['body']);
    }

    /**
     * D-24: FedEx takes whole measures; they are sent rounded up, longest first, and the weight to a tenth.
     */
    public function testPackagesInCentimetresAndKilogramsAreSentRoundedUpLongestFirst(): void
    {
        $this->process()->rate($this->request(new Package('Medium', 20.0, 45.2, 30.0, 'cm', 2.01, 'kg', [])));

        self::assertSame(
            [
                [
                    'weight' => ['units' => 'KG', 'value' => 2.1],
                    'dimensions' => ['length' => 46, 'width' => 30, 'height' => 20, 'units' => 'CM'],
                ],
            ],
            $this->sentShipment()['requestedPackageLineItems'] ?? null,
        );
    }

    /**
     * CA-46 and D-26.
     */
    #[DataProvider('pickupTypes')]
    public function testThePickupTypeIsSentAsFedexNamesIt(string $pickupType, string $fedexPickupType): void
    {
        $this->pickupType = $pickupType;

        $this->process()->rate($this->request());

        self::assertSame($fedexPickupType, $this->sentShipment()['pickupType'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pickupTypes(): iterable
    {
        yield 'scheduled pickup' => [CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED, 'USE_SCHEDULED_PICKUP'];
        yield 'dropped off' => [CarrierCredentialsInterface::PICKUP_TYPE_DROP_OFF, 'DROPOFF_AT_FEDEX_LOCATION'];
        yield 'pickup on demand' => [CarrierCredentialsInterface::PICKUP_TYPE_ON_DEMAND, 'CONTACT_FEDEX_TO_SCHEDULE'];
    }

    public function testTheSandboxAndProductionHostsFollowTheStoredEnvironment(): void
    {
        $this->process()->rate($this->request());
        self::assertStringStartsWith('https://apis-sandbox.fedex.com/', $this->rateRequests[0]['url']);

        $this->environment = CarrierCredentialsInterface::ENVIRONMENT_PRODUCTION;
        $this->process()->rate($this->request());
        self::assertStringStartsWith('https://apis.fedex.com/', $this->rateRequests[1]['url']);
    }

    /**
     * D-25: the token one process got serves the next one.
     */
    public function testASecondProcessReusesTheTokenWithoutRequestingAnother(): void
    {
        $this->process()->rate($this->request());
        $this->process()->rate($this->request());

        self::assertSame(1, $this->tokenRequests);
        self::assertCount(2, $this->rateRequests);
    }

    public function testTheTokenIsNotStoredInClear(): void
    {
        $this->process()->rate($this->request());

        foreach ($this->pool->getValues() as $value) {
            self::assertStringNotContainsString('fedex-access-token', \is_string($value) ? $value : serialize($value));
        }
        self::assertNotEmpty($this->pool->getValues());
    }

    /**
     * CA-47 and D-27: no request to FedEx, the token request included, waits longer than the carrier timeout.
     */
    public function testEveryRequestCarriesTheCarrierTimeout(): void
    {
        $this->timeout = 4.5;

        $this->process()->rate($this->request());

        self::assertSame([[4.5, 4.5], [4.5, 4.5]], $this->timeouts);
    }

    public function testWithoutAnAccountNumberFedexIsNotCalled(): void
    {
        unset($this->credentials[CarrierCredentialsInterface::ACCOUNT_NUMBER]);

        try {
            $this->process()->rate($this->request());
            self::fail('FedEx was quoted without an account number.');
        } catch (CarrierCredentialsException) {
            self::assertSame([0, []], [$this->tokenRequests, $this->rateRequests]);
        }
    }

    public function testARejectedRequestKeepsWhatFedexSaid(): void
    {
        $this->mockFedex(new MockResponse($this->fixture('error.json'), 400, ['Content-Type' => 'application/json']));

        $this->expectException(CarrierRejectedRequestException::class);
        $this->expectExceptionMessage('RECIPIENT.POSTALCODE.INVALID: Recipient postal code or routing code is required');
        $this->process()->rate($this->request());
    }

    private function process(): FedexCarrier
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_FEDEX);
        $credentials->setEnvironment($this->environment);
        $credentials->setPickupType($this->pickupType);
        $credentials->setCredentials($this->credentials);

        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($credentials);

        return new FedexCarrier(
            new CredentialsProvider($repository),
            new FedexConnectorFactory($this->pool, new Encrypter($this->keyPath), $this->lockFactory, $this->timeout),
        );
    }

    private function mockFedex(MockResponse $rateResponse): void
    {
        MockClient::destroyGlobal();
        MockClient::global([
            ApiAuthorization::class => function (PendingRequest $pendingRequest): MockResponse {
                ++$this->tokenRequests;
                $this->timeouts[] = [$pendingRequest->config()->get('connect_timeout'), $pendingRequest->config()->get('timeout')];

                return new MockResponse($this->fixture('token.json'), 200, ['Content-Type' => 'application/json']);
            },
            RateAndTransitTimes::class => function (PendingRequest $pendingRequest) use ($rateResponse): MockResponse {
                $this->timeouts[] = [$pendingRequest->config()->get('connect_timeout'), $pendingRequest->config()->get('timeout')];
                $body = $pendingRequest->body()?->all();
                $this->rateRequests[] = ['url' => $pendingRequest->getUrl(), 'body' => \is_array($body) ? $body : []];

                return $rateResponse;
            },
        ]);
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
     * @return array<array-key, mixed>
     */
    private function sentShipment(): array
    {
        $shipment = $this->rateRequests[0]['body']['requestedShipment'] ?? null;
        self::assertIsArray($shipment);

        return $shipment;
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }
}
