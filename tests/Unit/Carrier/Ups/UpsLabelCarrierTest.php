<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier\Ups;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierHttpClientFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsAccessTokenCache;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsClientFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups\UpsLabelCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Against the fixtures in `fixtures/`, written from the SDK's schemas and not checked against UPS yet.
 */
final class UpsLabelCarrierTest extends TestCase
{
    /** @var list<array{url: string, body: array<array-key, mixed>}> */
    private array $requests = [];

    /** @var array<string, string> */
    private array $credentials = [
        CarrierCredentialsInterface::CLIENT_ID => 'ups-client-id',
        CarrierCredentialsInterface::CLIENT_SECRET => 'ups-client-secret',
        CarrierCredentialsInterface::ACCOUNT_NUMBER => 'A1B2C3',
    ];

    /** @var array<string, string> */
    private array $formats = [];

    private string $keyPath;

    protected function setUp(): void
    {
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_ups_label_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    public function testEveryPackageComesBackWithItsOwnLabelAndTrackingNumber(): void
    {
        $carrier = $this->carrier($this->json($this->fixture('shipment-two-packages.json')));

        $result = $carrier->ship($this->request(packages: 2));

        self::assertSame('1Z999AA10123456784', $result->carrierReference);
        self::assertCount(2, $result->labels);
        self::assertSame([0, 1], array_map(static fn ($label): int => $label->position, $result->labels));
        self::assertSame(
            ['1Z999AA10123456784', '1Z999AA10123456785'],
            array_map(static fn ($label): string => $label->trackingNumber, $result->labels),
        );
        // The bytes, decoded, not the base64 UPS sends.
        self::assertSame('GIF89a a UPS label', $result->labels[0]->contents);
        self::assertSame('GIF', $result->labels[0]->format);
    }

    public function testTheShipmentIsSentWithBothEndsAndTheServiceThatWasCharged(): void
    {
        $this->carrier()->ship($this->request());

        self::assertSame('03', $this->sent('Shipment.Service.Code'));
        self::assertSame('The store', $this->sent('Shipment.Shipper.Name'));
        self::assertSame('Ada Lovelace', $this->sent('Shipment.Shipper.AttentionName'));
        self::assertSame('13057800955', $this->sent('Shipment.Shipper.Phone.Number'));
        self::assertSame('A1B2C3', $this->sent('Shipment.Shipper.ShipperNumber'));
        self::assertSame('Chicago', $this->sent('Shipment.ShipFrom.Address.City'));
        self::assertSame('Grace Hopper', $this->sent('Shipment.ShipTo.AttentionName'));
        self::assertSame('Seattle', $this->sent('Shipment.ShipTo.Address.City'));
    }

    /**
     * The shipment is billed to the merchant's own account, which is the one the labels are issued against.
     */
    public function testTheShipmentIsBilledToTheMerchantsAccount(): void
    {
        $this->carrier()->ship($this->request());

        self::assertSame('01', $this->sent('Shipment.PaymentInformation.ShipmentCharge.0.Type'));
        self::assertSame('A1B2C3', $this->sent('Shipment.PaymentInformation.ShipmentCharge.0.BillShipper.AccountNumber'));
    }

    public function testThePackagesAreSentLongestSideFirstAndRoundedUp(): void
    {
        $this->carrier()->ship($this->request(package: new Package('Medium', 11.0, 13.2, 9.0, 'in', 5.55, 'lb', [])));

        self::assertSame('02', $this->sent('Shipment.Package.0.Packaging.Code'));
        self::assertSame('IN', $this->sent('Shipment.Package.0.Dimensions.UnitOfMeasurement.Code'));
        self::assertSame('14', $this->sent('Shipment.Package.0.Dimensions.Length'));
        self::assertSame('11', $this->sent('Shipment.Package.0.Dimensions.Width'));
        self::assertSame('9', $this->sent('Shipment.Package.0.Dimensions.Height'));
        self::assertSame('LBS', $this->sent('Shipment.Package.0.PackageWeight.UnitOfMeasurement.Code'));
        self::assertSame('5.6', $this->sent('Shipment.Package.0.PackageWeight.Weight'));
    }

    /**
     * The shop without a label printer gets something it can open; the warehouse asks for ZPL instead.
     */
    public function testTheLabelIsAskedForInTheConfiguredFormat(): void
    {
        $this->carrier()->ship($this->request());
        self::assertSame('GIF', $this->sent('LabelSpecification.LabelImageFormat.Code'));

        $this->requests = [];
        $this->formats = [CarrierCredentialsInterface::CARRIER_UPS => 'ZPL'];
        $this->carrier()->ship($this->request());
        self::assertSame('ZPL', $this->sent('LabelSpecification.LabelImageFormat.Code'));
    }

    /**
     * The address was good enough to be rated and paid for. Refusing to print now would leave a paid order
     * nobody can ship.
     */
    public function testUpsIsAskedNotToSecondGuessTheAddresses(): void
    {
        $this->carrier()->ship($this->request());

        self::assertSame('nonvalidate', $this->sent('Request.RequestOption'));
    }

    public function testWithoutAnAccountNumberUpsIsNotCalled(): void
    {
        unset($this->credentials[CarrierCredentialsInterface::ACCOUNT_NUMBER]);

        try {
            $this->carrier()->ship($this->request());
            self::fail('Expected a CarrierCredentialsException.');
        } catch (CarrierCredentialsException) {
            self::assertSame([], $this->requests);
        }
    }

    /**
     * A carrier refuses a label with nobody to deliver to, and the plugin says so before spending a call.
     */
    public function testWithoutANameAndAPhoneAtBothEndsUpsIsNotCalled(): void
    {
        $nobody = new Address('US', '98101', 'Seattle', '500 Pine St', 'WA');

        try {
            $this->carrier()->ship($this->request(destination: $nobody));
            self::fail('Expected a CarrierRejectedRequestException.');
        } catch (CarrierRejectedRequestException) {
            self::assertSame([], $this->requests);
        }
    }

    public function testARejectedRequestKeepsWhatUpsSaid(): void
    {
        $carrier = $this->carrier(new MockResponse($this->fixture('error.json'), [
            'http_code' => 400,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $this->expectException(CarrierRejectedRequestException::class);
        $this->expectExceptionMessage('111210: The requested service is unavailable between the selected locations.');
        $carrier->ship($this->request());
    }

    /**
     * Fewer labels than packages would leave a parcel with nothing to stick on it.
     */
    public function testAnAnswerWithFewerLabelsThanPackagesIsRefused(): void
    {
        $carrier = $this->carrier($this->json($this->fixture('shipment-two-packages.json')));

        $this->expectException(UnexpectedCarrierResponseException::class);
        $this->expectExceptionMessage('UPS answered with 2 label(s) for a shipment of 3 package(s).');

        $carrier->ship($this->request(packages: 3));
    }

    public function testAnAnswerWithoutAShipmentIdentificationNumberIsRefused(): void
    {
        $carrier = $this->carrier($this->json('{"ShipmentResponse":{"ShipmentResults":{"PackageResults":[]}}}'));

        $this->expectException(UnexpectedCarrierResponseException::class);
        $carrier->ship($this->request());
    }

    public function testAPackageWithoutAReadableLabelIsRefused(): void
    {
        $carrier = $this->carrier($this->json(
            '{"ShipmentResponse":{"ShipmentResults":{"ShipmentIdentificationNumber":"1Z999AA10123456784","PackageResults":[{"TrackingNumber":"1Z999AA10123456784"}]}}}',
        ));

        $this->expectException(UnexpectedCarrierResponseException::class);
        $this->expectExceptionMessage('carries no readable label');
        $carrier->ship($this->request());
    }

    public function testAVoidUpsAcceptsIsAVoid(): void
    {
        $result = $this->carrier($this->json($this->fixture('void.json')))->void('1Z999AA10123456784');

        self::assertTrue($result->voided);
        self::assertNull($result->reason);
    }

    /**
     * The label stays issued. Reading this as a success is what makes a warehouse ship a parcel the shop
     * believes was cancelled.
     */
    public function testAVoidUpsRefusesSaysSoAndSaysWhy(): void
    {
        $result = $this->carrier($this->json($this->fixture('void-refused.json')))->void('1Z999AA10123456784');

        self::assertFalse($result->voided);
        self::assertStringContainsString('already been picked up', (string) $result->reason);
    }

    /**
     * A refusal arrives as a rejected request, and is still an answer rather than a failure of the plugin.
     */
    public function testAVoidUpsRejectsOutrightIsAlsoARefusal(): void
    {
        $carrier = $this->carrier(new MockResponse($this->fixture('error.json'), [
            'http_code' => 400,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $result = $carrier->void('1Z999AA10123456784');

        self::assertFalse($result->voided);
        self::assertNotNull($result->reason);
    }

    /**
     * This is what makes the ambiguity resolvable without a person: UPS can be asked whether it did issue a
     * shipment nobody got an answer for.
     */
    public function testAShipmentUpsDidIssueComesBackWithItsLabels(): void
    {
        $result = $this->carrier($this->json($this->fixture('label-recovery.json')))->recover('1Z999AA10123456784');

        self::assertNotNull($result);
        self::assertSame('1Z999AA10123456784', $result->carrierReference);
        self::assertCount(2, $result->labels);
        self::assertSame('GIF89a a recovered UPS label', $result->labels[0]->contents);
        self::assertSame('1Z999AA10123456785', $result->labels[1]->trackingNumber);
    }

    /**
     * Null is the answer «I never issued it», not a failure.
     */
    public function testAShipmentUpsNeverIssuedRecoversNothing(): void
    {
        $carrier = $this->carrier(new MockResponse($this->fixture('error.json'), [
            'http_code' => 400,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        self::assertNull($carrier->recover('1Z999AA10123456784'));
    }

    public function testARecoveryWithoutAnyLabelRecoversNothing(): void
    {
        $carrier = $this->carrier($this->json('{"LabelRecoveryResponse":{"ShipmentIdentificationNumber":"1Z999AA10123456784","LabelResults":[]}}'));

        self::assertNull($carrier->recover('1Z999AA10123456784'));
    }

    private function carrier(?MockResponse $shipResponse = null): UpsLabelCarrier
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_UPS);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $credentials->setCredentials($this->credentials);

        $shipResponse ??= $this->json($this->fixture('shipment.json'));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($shipResponse): MockResponse {
            if (str_contains($url, '/security/v1/oauth/token')) {
                // The fixture leaves the moment it was issued to the test, so the token is never already stale.
                return $this->json(strtr($this->fixture('token.json'), ['%issued_at%' => (string) (time() * 1000)]));
            }

            // A void carries its number in the URL and no body at all, so an empty one is not an error.
            $body = $options['body'] ?? '';
            $this->requests[] = [
                'url' => $url,
                'body' => \is_string($body) && '' !== $body ? (array) json_decode($body, true, flags: \JSON_THROW_ON_ERROR) : [],
            ];

            return $shipResponse;
        });

        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($credentials);

        return new UpsLabelCarrier(
            new CredentialsProvider($repository),
            new UpsClientFactory(
                CarrierHttpClientFactory::create($httpClient, 10.0),
                new UpsAccessTokenCache(new ArrayAdapter(), new Encrypter($this->keyPath)),
                new LockFactory(new InMemoryStore()),
            ),
            new LabelFormats($this->formats),
        );
    }

    private function request(int $packages = 1, ?Package $package = null, ?Address $destination = null): ShipmentRequest
    {
        $package ??= new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', []);

        return new ShipmentRequest(
            new Address('US', '60601', 'Chicago', '1 Main St', 'IL', false, 'The store', 'Ada Lovelace', '13057800955'),
            $destination ?? new Address('US', '98101', 'Seattle', '500 Pine St', 'WA', true, null, 'Grace Hopper', '12065550100'),
            '03',
            $this->packages($packages, $package),
            'PDF',
        );
    }

    /**
     * @return non-empty-list<ShipmentPackage>
     */
    private function packages(int $count, Package $package): array
    {
        $packages = [new ShipmentPackage($package)];
        for ($i = 1; $i < $count; ++$i) {
            $packages[] = new ShipmentPackage($package);
        }

        return $packages;
    }

    /**
     * Reads a value out of what was sent, by its path: «Shipment.Service.Code».
     */
    private function sent(string $path): mixed
    {
        $value = $this->sentRequest();
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function sentRequest(): array
    {
        self::assertNotSame([], $this->requests);
        $request = $this->requests[0]['body']['ShipmentRequest'] ?? null;
        self::assertIsArray($request);

        return $request;
    }

    private function json(string $body): MockResponse
    {
        return new MockResponse($body, ['response_headers' => ['content-type' => 'application/json']]);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }
}
