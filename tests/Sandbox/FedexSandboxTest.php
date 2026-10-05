<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Sandbox;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexConnectorFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexLabelCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsInvoice;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsItem;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Encrypter;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\CarrierServices;
use ParagonIE\Halite\KeyFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Response;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Talks to the real FedEx sandbox. It is not part of any test suite of `phpunit.xml.dist`, so
 * `vendor/bin/phpunit` never runs it; give it the path to run it.
 *
 * It needs CARRIER_SANDBOX_FEDEX_CLIENT_ID, CARRIER_SANDBOX_FEDEX_CLIENT_SECRET and
 * CARRIER_SANDBOX_FEDEX_ACCOUNT_NUMBER in tests/TestApplication/.env.test.local, and skips without them.
 *
 * What it answers: whether the adapters read a real answer the way they read the fixtures, which service codes
 * the account actually sells, and whether FedEx takes the shipments the plugin asks for. It writes FedEx's answers
 * to var/sandbox/ as they came, to compare with the fixtures, and cancels every shipment it issues.
 *
 * The sandbox fails now and then with HTTP 500 or 503 on a request it takes the next time: a red run is run again
 * before it is believed.
 */
final class FedexSandboxTest extends TestCase
{
    /** A service the sandbox sells inside the United States; it sells no Ground. */
    private const DOMESTIC_SERVICE = 'FEDEX_2_DAY';

    /** FedEx's standard service across a border. */
    private const INTERNATIONAL_SERVICE = 'FEDEX_INTERNATIONAL_PRIORITY';

    /** The answers worth comparing with a fixture. Not the token's. */
    private const RECORDED_ANSWERS = ['RateAndTransitTimes', 'TrackByTrackingNumber', 'CreateShipment', 'CancelShipment'];

    private string $keyPath;

    private FedexConnectorFactory $connectorFactory;

    private int $answers = 0;

    protected function setUp(): void
    {
        foreach (['CLIENT_ID', 'CLIENT_SECRET', 'ACCOUNT_NUMBER'] as $variable) {
            if ('' === self::environmentVariable('CARRIER_SANDBOX_FEDEX_' . $variable)) {
                self::markTestSkipped('There are no FedEx sandbox credentials in tests/TestApplication/.env.test.local.');
            }
        }

        $this->keyPath = sys_get_temp_dir() . '/jpmmartin_carrier_sandbox_' . bin2hex(random_bytes(8)) . '.key';
        KeyFactory::save(KeyFactory::generateEncryptionKey(), $this->keyPath);
    }

    protected function tearDown(): void
    {
        if (isset($this->keyPath) && is_file($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    /**
     * A shipment inside the United States, which is what a FedEx test account is opened for.
     */
    public function testFedexRatesAShipmentAndTheAdapterReadsTheAnswer(): void
    {
        $rates = $this->carrier()->rate(new RateRequest(
            new Address('US', '60601', 'Chicago', '1 Main St', 'IL'),
            new Address('US', '98101', 'Seattle', '500 Pine St', 'WA'),
            [new Package('Medium', 11.0, 13.2, 9.0, 'in', 5.55, 'lb', [])],
        ));

        $quoted = [];
        foreach ($rates->all() as $rate) {
            $quoted[$rate->serviceCode] = ['amount' => $rate->amount, 'currency' => $rate->currencyCode];
        }

        $this->record('fedex-services-us.json', $quoted);

        self::assertNotSame([], $quoted, 'FedEx quoted no service at all.');
        foreach ($rates->all() as $rate) {
            self::assertInstanceOf(Rate::class, $rate);
            self::assertGreaterThan(0, $rate->amount, sprintf('The service %s came back with no amount.', $rate->serviceCode));
            self::assertNotSame('', $rate->currencyCode);
        }
    }

    /**
     * The list the plugin ships with was written from the SDK's examples, never from an account. This is the
     * test that says whether those codes exist for a real one.
     */
    public function testTheServicesThePluginShipsWithAreSoldByTheAccount(): void
    {
        $rates = $this->carrier()->rate(new RateRequest(
            new Address('US', '60601', 'Chicago', '1 Main St', 'IL'),
            new Address('US', '98101', 'Seattle', '500 Pine St', 'WA'),
            [new Package('Medium', 11.0, 13.2, 9.0, 'in', 5.55, 'lb', [])],
        ));

        $sold = array_map(static fn (Rate $rate): string => $rate->serviceCode, $rates->all());
        $shipped = array_keys(CarrierServices::DEFAULTS['fedex']);

        $this->record('fedex-services-shipped-vs-sold.json', ['shipped' => $shipped, 'sold' => $sold]);

        // The sandbox quotes no Ground service on any route, and answers GROUND.SERVICES.UNAVAILABLE when asked for
        // one. That does not tell a test account without Ground from a service that is gone, so Ground stays on
        // the list until FedEx says which; any other code the account does not sell still fails here.
        self::assertSame([], array_values(array_diff($shipped, $sold, ['FEDEX_GROUND'])), 'The plugin offers services this account does not sell.');
    }

    /**
     * Asks a second API of the same project. It tells a sandbox that is down from a project whose Rate API is
     * simply not provisioned: if this one answers and the rates do not, it is not FedEx being down.
     */
    public function testTheTrackingApiOfTheSameProjectAnswers(): void
    {
        $tracking = $this->carrier()->track('123456789012');

        $this->record('fedex-tracking.json', [
            'trackingNumber' => $tracking->trackingNumber,
            'status' => $tracking->status,
            'events' => array_map(static fn ($event): array => [
                'occurredAt' => $event->occurredAt?->format('c'),
                'description' => $event->description,
                'location' => $event->location,
            ], $tracking->events),
        ]);

        self::assertSame('123456789012', $tracking->trackingNumber);
    }

    /**
     * A label for one package inside the United States, its cancellation, and the same cancellation again: whether
     * the adapter reads FedEx's answers to issuing and to cancelling, and how FedEx says no.
     */
    public function testFedexIssuesALabelAndCancelsIt(): void
    {
        $carrier = $this->labelCarrier();

        $result = $carrier->ship($this->shipment(self::DOMESTIC_SERVICE, 1));

        self::assertNotSame('', $result->carrierReference);
        self::assertCount(1, $result->labels);
        self::assertNotSame('', $result->labels[0]->trackingNumber);
        self::assertStringStartsWith('%PDF', $result->labels[0]->contents);

        $cancelled = $carrier->void($result->carrierReference);
        self::assertTrue($cancelled->voided, (string) $cancelled->reason);

        $again = $carrier->void($result->carrierReference);
        $this->record('fedex-cancel-again.json', ['voided' => $again->voided, 'reason' => $again->reason]);
    }

    public function testFedexIssuesOneLabelPerPackage(): void
    {
        $carrier = $this->labelCarrier();

        $result = $carrier->ship($this->shipment(self::DOMESTIC_SERVICE, 2));

        self::assertCount(2, $result->labels);
        self::assertNotSame($result->labels[0]->trackingNumber, $result->labels[1]->trackingNumber);

        $carrier->void($result->carrierReference);
    }

    /**
     * Every label format the plugin accepts for FedEx is one FedEx issues, and one it does not accept, GIF, FedEx
     * refuses as well.
     */
    public function testFedexIssuesEveryLabelFormatThePluginAcceptsAndRefusesAnother(): void
    {
        $carrier = $this->labelCarrier();
        foreach (LabelFormats::SUPPORTED[CarrierCredentialsInterface::CARRIER_FEDEX] as $format) {
            $result = $carrier->ship($this->shipment(self::DOMESTIC_SERVICE, 1, format: $format));

            self::assertSame($format, $result->labels[0]->format, sprintf('FedEx issued %s as something else.', $format));
            self::assertNotSame('', $result->labels[0]->contents);
            $carrier->void($result->carrierReference);
        }

        $this->expectException(CarrierRejectedRequestException::class);
        $carrier->ship($this->shipment(self::DOMESTIC_SERVICE, 1, format: 'GIF'));
    }

    /**
     * To the United Kingdom, with an invoice of two lines and the recipient paying the duties. What the SDK does not
     * say of customs, FedEx answers here: it takes no payor when the recipient pays and `EA` as the unit, and it
     * refuses a line without its weight.
     */
    public function testFedexIssuesAnInternationalShipmentWithItsInvoice(): void
    {
        $london = new Address('GB', 'SW1A 1AA', 'London', '10 Downing St', null, false, null, 'Grace Hopper', '442079460000');
        $services = array_map(
            static fn (Rate $rate): string => $rate->serviceCode,
            $this->carrier()->rate(new RateRequest(
                new Address('US', '60601', 'Chicago', '1 Main St', 'IL'),
                $london,
                [new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', [])],
            ))->all(),
        );
        $this->record('fedex-services-gb.json', $services);
        self::assertContains(self::INTERNATIONAL_SERVICE, $services, 'FedEx does not sell International Priority from Chicago to London.');

        $carrier = $this->labelCarrier();
        $result = $carrier->ship($this->shipment(self::INTERNATIONAL_SERVICE, 1, $london, new CustomsInvoice('000000042', new \DateTimeImmutable(), 'USD', [
            new CustomsItem('691200', 'PT', 'Enamel mug', 2, 1200, 'USD', 'MUG', 2.5, 'lb'),
            new CustomsItem('420222', 'CN', 'Leather bag', 1, 3550, 'USD', 'BAG', 2.2, 'lb'),
        ])));

        self::assertCount(1, $result->labels);
        self::assertNotNull($result->customsDocument, 'FedEx issued the label without the commercial invoice.');
        self::assertStringStartsWith('%PDF', $result->customsDocument->contents);

        $carrier->void($result->carrierReference);
    }

    private function carrier(): FedexCarrier
    {
        return new FedexCarrier($this->credentialsProvider(), $this->connectorFactory());
    }

    private function labelCarrier(): FedexLabelCarrier
    {
        return new FedexLabelCarrier($this->credentialsProvider(), $this->connectorFactory());
    }

    private function credentials(): CarrierCredentials
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_FEDEX);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $credentials->setDutiesPayer(CarrierCredentialsInterface::DUTIES_PAYER_RECIPIENT);
        $credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => self::environmentVariable('CARRIER_SANDBOX_FEDEX_CLIENT_ID'),
            CarrierCredentialsInterface::CLIENT_SECRET => self::environmentVariable('CARRIER_SANDBOX_FEDEX_CLIENT_SECRET'),
            CarrierCredentialsInterface::ACCOUNT_NUMBER => self::environmentVariable('CARRIER_SANDBOX_FEDEX_ACCOUNT_NUMBER'),
        ]);

        return $credentials;
    }

    private function credentialsProvider(): CredentialsProvider
    {
        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($this->credentials());

        return new CredentialsProvider($repository);
    }

    /**
     * One per test, so that every adapter of the test talks through the same connector, and that connector leaves
     * FedEx's answers as they came in var/sandbox/: what a fixture is checked against. The token's answer is never
     * written, since it is a credential.
     */
    private function connectorFactory(): FedexConnectorFactory
    {
        if (isset($this->connectorFactory)) {
            return $this->connectorFactory;
        }

        $this->connectorFactory = new FedexConnectorFactory(new ArrayAdapter(), new Encrypter($this->keyPath), new LockFactory(new InMemoryStore()), 30.0);
        $this->connectorFactory->create($this->credentials())->middleware()->onResponse(function (Response $response): void {
            $request = (new \ReflectionClass($response->getRequest()))->getShortName();
            if (!\in_array($request, self::RECORDED_ANSWERS, true)) {
                return;
            }

            $answer = json_decode($response->body(), true);
            $this->record(sprintf('fedex-raw-%s-%02d-%s.json', $this->name(), ++$this->answers, $request), \is_array($answer) ? $answer : ['body' => $response->body()]);
        });

        return $this->connectorFactory;
    }

    /**
     * A shipment of the given packages from Chicago, to Seattle unless told otherwise, as the store would ask for it.
     *
     * @param int<1, max> $packages
     */
    private function shipment(string $service, int $packages, ?Address $destination = null, ?CustomsInvoice $invoice = null, string $format = 'PDF'): ShipmentRequest
    {
        $package = new ShipmentPackage(new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', []));

        return new ShipmentRequest(
            new Address('US', '60601', 'Chicago', '1 Main St', 'IL', false, 'The store', 'Ada Lovelace', '3125550100'),
            $destination ?? new Address('US', '98101', 'Seattle', '500 Pine St', 'WA', true, null, 'Grace Hopper', '2065550100'),
            $service,
            array_merge([$package], array_fill(0, $packages - 1, $package)),
            $format,
            'sandbox-' . bin2hex(random_bytes(4)),
            $invoice,
        );
    }

    /**
     * Leaves what the carrier said where a person can read it, since a test may not print.
     *
     * @param array<array-key, mixed> $contents
     */
    private function record(string $name, array $contents): void
    {
        $directory = \dirname(__DIR__, 2) . '/var/sandbox';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $name, json_encode($contents, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    private static function environmentVariable(string $name): string
    {
        return trim((string) ($_SERVER[$name] ?? $_ENV[$name] ?? ''));
    }
}
