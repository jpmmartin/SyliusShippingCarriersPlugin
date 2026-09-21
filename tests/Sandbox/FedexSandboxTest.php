<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Sandbox;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex\FedexConnectorFactory;
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
 * What it answers: whether the adapter reads a real answer the way it reads the fixtures, and which service
 * codes the account actually sells. It writes those codes to var/sandbox/ for the tasks that fix the list.
 */
final class FedexSandboxTest extends TestCase
{
    private string $keyPath;

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

        self::assertSame([], array_diff($shipped, $sold), 'The plugin offers services this account does not sell.');
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

    private function carrier(): FedexCarrier
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_FEDEX);
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => self::environmentVariable('CARRIER_SANDBOX_FEDEX_CLIENT_ID'),
            CarrierCredentialsInterface::CLIENT_SECRET => self::environmentVariable('CARRIER_SANDBOX_FEDEX_CLIENT_SECRET'),
            CarrierCredentialsInterface::ACCOUNT_NUMBER => self::environmentVariable('CARRIER_SANDBOX_FEDEX_ACCOUNT_NUMBER'),
        ]);

        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturn($credentials);

        return new FedexCarrier(
            new CredentialsProvider($repository),
            new FedexConnectorFactory(new ArrayAdapter(), new Encrypter($this->keyPath), new LockFactory(new InMemoryStore()), 30.0),
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
