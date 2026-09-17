<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Tracking;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShipmentCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingEvent;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Registry\ServiceRegistry;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Calculator\FlatRateCalculator;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

final class TrackingProviderTest extends TestCase
{
    private const LIFETIME = 300;

    private ArrayAdapter $cache;

    private RecordingLogger $logger;

    private TrackingCarrier $ups;

    private ?CarrierCredentials $credentials;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->logger = new RecordingLogger();
        $this->ups = new TrackingCarrier(new TrackingInfo('1Z999AA10123456784', 'Delivered', [
            new TrackingEvent(new \DateTimeImmutable('2026-09-17 10:15:00'), 'Delivered', 'Seattle, WA, US'),
        ]));

        $this->credentials = new CarrierCredentials();
        $this->credentials->setCarrier(CarrierCredentialsInterface::CARRIER_UPS);
        $this->credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $this->credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $this->credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => 'ups-client-id',
            CarrierCredentialsInterface::CLIENT_SECRET => 'ups-client-secret',
        ]);
    }

    public function testTheCarrierOfTheShippingMethodIsAsked(): void
    {
        $tracking = $this->provider()->track($this->shipment());

        self::assertSame('Delivered', $tracking?->status);
        self::assertSame(['1Z999AA10123456784'], $this->ups->enquiries);
    }

    /**
     * Opening the same order again does not ask the carrier again.
     */
    public function testTheSameShipmentAskedTwiceIsOnlyOneEnquiry(): void
    {
        $this->provider()->track($this->shipment());
        $tracking = $this->provider()->track($this->shipment());

        self::assertSame('Delivered', $tracking?->status);
        self::assertCount(1, $this->ups->enquiries);
    }

    public function testTheEventsSurviveBeingStored(): void
    {
        $this->provider()->track($this->shipment());
        $tracking = $this->provider()->track($this->shipment());

        self::assertEquals($this->ups->answer, $tracking);
    }

    public function testAShipmentWithoutATrackingNumberAsksNothing(): void
    {
        $shipment = $this->shipment();
        $shipment->setTracking(null);

        self::assertNull($this->provider()->track($shipment));
        self::assertSame([], $this->ups->enquiries);
    }

    public function testAShipmentOfAnotherCalculatorAsksNothing(): void
    {
        self::assertNull($this->provider()->track($this->shipment('flat_rate')));
        self::assertSame([], $this->ups->enquiries);
    }

    public function testWithoutCredentialsNothingIsAsked(): void
    {
        $this->credentials = null;

        self::assertNull($this->provider()->track($this->shipment()));
        self::assertSame([], $this->ups->enquiries);
    }

    /**
     * Storing the failure would leave the buyer without a status for minutes after the carrier recovered.
     */
    public function testAFailureIsNotStoredButIsRememberedForTheRequest(): void
    {
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');
        $provider = $this->provider();

        self::assertNull($provider->track($this->shipment()));
        self::assertNull($provider->track($this->shipment()));
        self::assertCount(1, $this->ups->enquiries);

        $provider->reset();
        self::assertNull($provider->track($this->shipment()));
        self::assertCount(2, $this->ups->enquiries);
    }

    public function testAStatusOlderThanItsLifetimeIsAskedAgain(): void
    {
        $this->provider()->track($this->shipment());

        // The pool forgets the entry the way it would once the lifetime is over.
        $this->cache->clear();
        $this->provider()->track($this->shipment());

        self::assertCount(2, $this->ups->enquiries);
    }

    /**
     * The buyer is only told the status is not available; the store has to be able to find out why.
     */
    public function testACarrierFailureIsLoggedWithTheCarrierTheShipmentAndTheCause(): void
    {
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');

        $this->provider()->track($this->shipment());

        self::assertCount(1, $this->logger->records);
        [$level, , $context] = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertSame('ups', $context['carrier'] ?? null);
        self::assertSame('1Z999AA10123456784', $context['tracking_number'] ?? null);
        self::assertSame('UPS did not answer in time.', $context['reason'] ?? null);
    }

    /**
     * A page with several shipments of a carrier that is down writes one entry, not one per shipment.
     */
    public function testAFailureRememberedForTheRequestIsLoggedOnce(): void
    {
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');
        $provider = $this->provider();

        $provider->track($this->shipment());
        $provider->track($this->shipment());

        self::assertCount(1, $this->logger->records);
    }

    public function testCredentialsThatCannotBeReadAreLoggedOncePerRequest(): void
    {
        $this->credentials = null;
        $provider = $this->provider();

        $provider->track($this->shipment());
        $provider->track($this->shipment());

        self::assertCount(1, $this->logger->records);
        [$level, , $context] = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertSame('ups', $context['carrier'] ?? null);
        self::assertSame('1Z999AA10123456784', $context['tracking_number'] ?? null);
        self::assertNotEmpty($context['reason'] ?? null);

        $provider->reset();
        $provider->track($this->shipment());
        self::assertCount(2, $this->logger->records);
    }

    private function provider(): TrackingProvider
    {
        $calculators = new ServiceRegistry(CalculatorInterface::class);
        $chargeResolver = new ShippingChargeResolver($this->createStub(RateProviderInterface::class));
        $calculators->register('ups_rate', new CarrierRateCalculator($chargeResolver, 'ups', 'ups_rate'));
        $calculators->register('flat_rate', new FlatRateCalculator());

        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $credentialsRepository */
        $credentialsRepository = $this->createStub(RepositoryInterface::class);
        $credentialsRepository->method('findOneBy')->willReturnCallback(fn (): ?CarrierCredentials => $this->credentials);

        return new TrackingProvider(
            new ShipmentCarrier($calculators),
            new CredentialsProvider($credentialsRepository),
            new ServiceLocator(['ups' => fn (): CarrierInterface => $this->ups]),
            $this->cache,
            $this->logger,
            self::LIFETIME,
        );
    }

    private function shipment(string $calculator = 'ups_rate'): Shipment
    {
        $method = new ShippingMethod();
        $method->setCalculator($calculator);

        $shipment = new Shipment();
        $shipment->setMethod($method);
        $shipment->setTracking('1Z999AA10123456784');

        return $shipment;
    }
}
