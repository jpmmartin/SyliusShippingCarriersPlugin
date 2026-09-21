<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier\Fedex;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexConnectorFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexLabelCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\RateLimitPlugin\Stores\MemoryStore;
use ShipStream\FedEx\Api\AuthorizationV1\Requests\ApiAuthorization;
use ShipStream\FedEx\Api\ShipV1\Requests\CancelShipment;
use ShipStream\FedEx\Api\ShipV1\Requests\CreateShipment;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Against the fixtures in `fixtures/`, written from the SDK's schemas and not checked against FedEx yet.
 */
final class FedexLabelCarrierTest extends TestCase
{
    /** @var list<array<array-key, mixed>> */
    private array $sent = [];

    /** @var array<string, string> */
    private array $credentials = [
        CarrierCredentialsInterface::CLIENT_ID => 'fedex-client-id',
        CarrierCredentialsInterface::CLIENT_SECRET => 'fedex-client-secret',
        CarrierCredentialsInterface::ACCOUNT_NUMBER => '740561073',
    ];

    /** @var array<string, string> */
    private array $formats = [];

    private string $keyPath;

    protected function setUp(): void
    {
        // The SDK keeps its rate limits in a static store, shared by every test of the process.
        MemoryStore::clear();
        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_fedex_label_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    public function testEveryPackageComesBackWithItsOwnLabelAndTrackingNumber(): void
    {
        $this->mockFedex($this->json($this->fixture('ship-two-packages.json')));

        $result = $this->carrier()->ship($this->request(packages: 2));

        self::assertSame('794953555571', $result->carrierReference);
        self::assertCount(2, $result->labels);
        self::assertSame([0, 1], array_map(static fn ($label): int => $label->position, $result->labels));
        self::assertSame(['794953555571', '794953555572'], array_map(static fn ($label): string => $label->trackingNumber, $result->labels));
        // The bytes, decoded, not the base64 FedEx sends.
        self::assertSame('%PDF-1.4 a FedEx label', $result->labels[0]->contents);
        self::assertSame('PDF', $result->labels[0]->format);
    }

    public function testTheShipmentIsSentWithBothEndsTheServiceAndTheAccountItIsBilledTo(): void
    {
        $this->mockFedex($this->json($this->fixture('ship.json')));

        $this->carrier()->ship($this->request());

        self::assertSame('FEDEX_GROUND', $this->sent('requestedShipment.serviceType'));
        self::assertSame('USE_SCHEDULED_PICKUP', $this->sent('requestedShipment.pickupType'));
        self::assertSame('YOUR_PACKAGING', $this->sent('requestedShipment.packagingType'));
        self::assertSame('SENDER', $this->sent('requestedShipment.shippingChargesPayment.paymentType'));
        self::assertSame('740561073', $this->sent('accountNumber.value'));
        self::assertSame('The store', $this->sent('requestedShipment.shipper.contact.companyName'));
        self::assertSame('Ada Lovelace', $this->sent('requestedShipment.shipper.contact.personName'));
        self::assertSame('13057800955', $this->sent('requestedShipment.shipper.contact.phoneNumber'));
        self::assertSame('Chicago', $this->sent('requestedShipment.shipper.address.city'));
        self::assertSame('Grace Hopper', $this->sent('requestedShipment.recipients.0.contact.personName'));
        self::assertSame('Seattle', $this->sent('requestedShipment.recipients.0.address.city'));
    }

    /**
     * The bytes, not a link: FedEx's label URLs stop working after twelve hours, and a label has to be
     * printable for as long as the order is.
     */
    public function testTheLabelIsAskedForAsBytesInTheConfiguredFormat(): void
    {
        $this->mockFedex($this->json($this->fixture('ship.json')));
        $this->carrier()->ship($this->request());

        self::assertSame('LABEL', $this->sent('labelResponseOptions'));
        self::assertSame('PDF', $this->sent('requestedShipment.labelSpecification.imageType'));
        self::assertSame('PAPER_4X6', $this->sent('requestedShipment.labelSpecification.labelStockType'));

        $this->sent = [];
        $this->formats = [CarrierCredentialsInterface::CARRIER_FEDEX => 'ZPLII'];
        $this->carrier()->ship($this->request());
        self::assertSame('ZPLII', $this->sent('requestedShipment.labelSpecification.imageType'));
    }

    public function testThePackagesAreSentLongestSideFirstAndRoundedUp(): void
    {
        $this->mockFedex($this->json($this->fixture('ship.json')));

        $this->carrier()->ship($this->request(package: new Package('Medium', 11.0, 13.2, 9.0, 'in', 5.55, 'lb', [])));

        self::assertSame(14, $this->sent('requestedShipment.requestedPackageLineItems.0.dimensions.length'));
        self::assertSame(11, $this->sent('requestedShipment.requestedPackageLineItems.0.dimensions.width'));
        self::assertSame(9, $this->sent('requestedShipment.requestedPackageLineItems.0.dimensions.height'));
        self::assertSame('IN', $this->sent('requestedShipment.requestedPackageLineItems.0.dimensions.units'));
        self::assertSame(5.6, $this->sent('requestedShipment.requestedPackageLineItems.0.weight.value'));
        self::assertSame('LB', $this->sent('requestedShipment.requestedPackageLineItems.0.weight.units'));
    }

    public function testWithoutAnAccountNumberFedexIsNotCalled(): void
    {
        $this->mockFedex($this->json($this->fixture('ship.json')));
        unset($this->credentials[CarrierCredentialsInterface::ACCOUNT_NUMBER]);

        try {
            $this->carrier()->ship($this->request());
            self::fail('Expected a CarrierCredentialsException.');
        } catch (CarrierCredentialsException) {
            self::assertSame([], $this->sent);
        }
    }

    public function testWithoutANameAndAPhoneAtBothEndsFedexIsNotCalled(): void
    {
        $this->mockFedex($this->json($this->fixture('ship.json')));
        $nobody = new Address('US', '98101', 'Seattle', '500 Pine St', 'WA');

        try {
            $this->carrier()->ship($this->request(destination: $nobody));
            self::fail('Expected a CarrierRejectedRequestException.');
        } catch (CarrierRejectedRequestException) {
            self::assertSame([], $this->sent);
        }
    }

    public function testAnAnswerWithFewerLabelsThanPackagesIsRefused(): void
    {
        $this->mockFedex($this->json($this->fixture('ship-two-packages.json')));

        $this->expectException(UnexpectedCarrierResponseException::class);
        $this->expectExceptionMessage('FedEx answered with 2 label(s) for a shipment of 3 package(s).');

        $this->carrier()->ship($this->request(packages: 3));
    }

    public function testACancellationFedexAcceptsIsAVoid(): void
    {
        $this->mockFedex($this->json($this->fixture('cancel.json')));

        $result = $this->carrier()->void('794953555571');

        self::assertTrue($result->voided);
        self::assertNull($result->reason);
    }

    /**
     * The label stays issued. Reading this as a success is what makes a warehouse ship a parcel the shop
     * believes was cancelled.
     */
    public function testACancellationFedexRefusesSaysSoAndSaysWhy(): void
    {
        $this->mockFedex($this->json($this->fixture('cancel-refused.json')));

        $result = $this->carrier()->void('794953555571');

        self::assertFalse($result->voided);
        self::assertStringContainsString('already been picked up', (string) $result->reason);
    }

    public function testACancellationFedexRejectsOutrightIsAlsoARefusal(): void
    {
        $this->mockFedex(new MockResponse($this->fixture('error.json'), 400, ['Content-Type' => 'application/json']));

        $result = $this->carrier()->void('794953555571');

        self::assertFalse($result->voided);
        self::assertNotNull($result->reason);
    }

    /**
     * This is the asymmetry with UPS, and it is not a gap in the plugin: FedEx has no operation that answers
     * «did you issue this?», so a shipment nobody got an answer for needs a person to look in its portal.
     */
    public function testFedexCannotSayWhetherItIssuedAShipment(): void
    {
        $this->mockFedex($this->json($this->fixture('ship.json')));

        self::assertNull($this->carrier()->recover('794953555571'));
        self::assertSame([], $this->sent);
    }

    private function carrier(): FedexLabelCarrier
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_FEDEX);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $credentials->setCredentials($this->credentials);

        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($credentials);

        return new FedexLabelCarrier(
            new CredentialsProvider($repository),
            new FedexConnectorFactory(new ArrayAdapter(), new Encrypter($this->keyPath), new LockFactory(new InMemoryStore()), 10.0),
            new LabelFormats($this->formats),
        );
    }

    private function mockFedex(MockResponse $response): void
    {
        MockClient::destroyGlobal();
        MockClient::global([
            ApiAuthorization::class => fn (): MockResponse => new MockResponse($this->fixture('token.json'), 200, ['Content-Type' => 'application/json']),
            CreateShipment::class => function (PendingRequest $pendingRequest) use ($response): MockResponse {
                $body = $pendingRequest->body()?->all();
                $this->sent[] = \is_array($body) ? $body : [];

                return $response;
            },
            CancelShipment::class => function (PendingRequest $pendingRequest) use ($response): MockResponse {
                $body = $pendingRequest->body()?->all();
                $this->sent[] = \is_array($body) ? $body : [];

                return $response;
            },
        ]);
    }

    private function request(int $packages = 1, ?Package $package = null, ?Address $destination = null): ShipmentRequest
    {
        $package ??= new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', []);
        $items = [new ShipmentPackage($package)];
        for ($i = 1; $i < $packages; ++$i) {
            $items[] = new ShipmentPackage($package);
        }

        return new ShipmentRequest(
            new Address('US', '60601', 'Chicago', '1 Main St', 'IL', false, 'The store', 'Ada Lovelace', '13057800955'),
            $destination ?? new Address('US', '98101', 'Seattle', '500 Pine St', 'WA', true, null, 'Grace Hopper', '12065550100'),
            'FEDEX_GROUND',
            $items,
            'PDF',
            'the shop reference',
        );
    }

    /**
     * Reads a value out of what was sent, by its path: «requestedShipment.serviceType».
     */
    private function sent(string $path): mixed
    {
        self::assertNotSame([], $this->sent);
        $value = $this->sent[0];
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    private function json(string $body): MockResponse
    {
        return new MockResponse($body, 200, ['Content-Type' => 'application/json']);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }
}
