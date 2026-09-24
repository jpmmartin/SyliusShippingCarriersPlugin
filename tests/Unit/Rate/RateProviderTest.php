<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Rate;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\AddressFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationTypeResolverInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\PackagingStrategyInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateCurrencyConverter;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateRequestFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateSet;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Currency\Converter\CurrencyConverter;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Currency\Model\ExchangeRate;
use Sylius\Component\Currency\Model\ExchangeRateInterface;
use Sylius\Component\Currency\Repository\ExchangeRateRepositoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsFactory;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

final class RateProviderTest extends TestCase
{
    private const LIFETIME = 900;

    private const RETENTION = 86400;

    private RecordingCarrier $ups;

    private ArrayAdapter $cache;

    private MockClock $clock;

    private RecordingLogger $logger;

    private ?CarrierShippingOrigin $origin;

    private ?CarrierCredentials $credentials;

    private string $destinationType = DestinationType::COMMERCIAL;

    /** @var non-empty-list<Package> */
    private array $packages;

    private bool $unpackable = false;

    private ?ExchangeRateInterface $exchangeRate = null;

    protected function setUp(): void
    {
        $this->ups = new RecordingCarrier(new RateSet([
            new Rate('03', 1540, 'USD'),
            new Rate('02', 2890, 'USD'),
            new Rate('01', 5125, 'USD'),
        ]));
        $this->cache = new ArrayAdapter();
        $this->clock = new MockClock('2026-09-17 10:00:00');
        $this->logger = new RecordingLogger();
        $this->packages = [new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', [])];

        $this->origin = new CarrierShippingOrigin();
        $this->origin->setStreet('1 Main St');
        $this->origin->setCity('Chicago');
        $this->origin->setPostcode('60601');
        $this->origin->setCountryCode('US');
        $this->origin->setProvinceCode('IL');

        $this->credentials = new CarrierCredentials();
        $this->credentials->setCarrier(CarrierCredentialsInterface::CARRIER_UPS);
        $this->credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $this->credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $this->credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => 'ups-client-id',
            CarrierCredentialsInterface::CLIENT_SECRET => 'ups-client-secret',
        ]);
    }

    /**
     * The shipping step asks once per service; the carrier is called once for all of them.
     */
    public function testOneCallToTheCarrierRatesEveryServiceOfTheShipment(): void
    {
        $provider = $this->provider();
        $shipment = $this->shipment();

        $amounts = array_map(
            static fn (string $service): ?int => $provider->rateFor($shipment, 'ups', $service)->rate?->amount,
            ['03', '02', '01'],
        );

        self::assertSame([1540, 2890, 5125], $amounts);
        self::assertCount(1, $this->ups->requests);
    }

    /**
     * Another request, such as the buyer coming back to the shipping step, reads what the first one stored.
     */
    public function testASecondVisitWithoutChangesDoesNotCallTheCarrier(): void
    {
        $this->provider()->rateFor($this->shipment(), 'ups', '03');

        $result = $this->provider()->rateFor($this->shipment(), 'ups', '02');

        self::assertSame(2890, $result->rate?->amount);
        self::assertCount(1, $this->ups->requests);
    }

    #[DataProvider('changesThatAskAgain')]
    public function testAChangeOfWhatThePriceDependsOnAsksTheCarrierAgain(string $change): void
    {
        $this->provider()->rateFor($this->shipment(), 'ups', '03');

        $postcode = '98101';
        match ($change) {
            'cart' => $this->packages = [new Package('Large', 20.0, 11.0, 9.0, 'in', 9.5, 'lb', [])],
            'destination' => $postcode = '98109',
            'destination type' => $this->destinationType = DestinationType::RESIDENTIAL,
            'origin' => $this->origin?->setPostcode('60602'),
            default => self::fail(sprintf('Unknown change "%s".', $change)),
        };
        $this->provider()->rateFor($this->shipment($postcode), 'ups', '03');

        self::assertCount(2, $this->ups->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function changesThatAskAgain(): iterable
    {
        yield 'the cart' => ['cart'];
        yield 'the destination' => ['destination'];
        yield 'the destination type' => ['destination type'];
        yield 'the origin' => ['origin'];
    }

    public function testTheCarrierIsAskedWithTheDestinationTypeOfTheOrder(): void
    {
        $this->destinationType = DestinationType::RESIDENTIAL;

        $this->provider()->rateFor($this->shipment(), 'ups', '03');

        self::assertTrue($this->ups->requests[0]->destination->residential);
    }

    public function testAStoredRateIsQuotedUntilItsLifetimeEnds(): void
    {
        $this->provider()->rateFor($this->shipment(), 'ups', '03');

        $this->clock->sleep(self::LIFETIME - 1);
        $this->provider()->rateFor($this->shipment(), 'ups', '03');
        self::assertCount(1, $this->ups->requests);

        $this->clock->sleep(1);
        $this->provider()->rateFor($this->shipment(), 'ups', '03');
        self::assertCount(2, $this->ups->requests);
    }

    /**
     * An expired rate is not quoted, but it is the last known rate when the carrier fails.
     */
    public function testAnExpiredRateStaysAsTheLastKnownRate(): void
    {
        $this->provider()->rateFor($this->shipment(), 'ups', '03');

        $this->clock->sleep(self::LIFETIME);
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');
        $result = $this->provider()->rateFor($this->shipment(), 'ups', '03');

        self::assertNull($result->rate);
        self::assertTrue($result->carrierFailed);
        self::assertSame(1540, $result->lastKnownRate?->amount);
        self::assertCount(2, $this->ups->requests);
    }

    public function testARateOlderThanTheRetentionIsNoLongerKnown(): void
    {
        $this->provider()->rateFor($this->shipment(), 'ups', '03');

        $this->clock->sleep(self::RETENTION);
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');
        $result = $this->provider()->rateFor($this->shipment(), 'ups', '03');

        self::assertTrue($result->carrierFailed);
        self::assertNull($result->lastKnownRate);
    }

    /**
     * A retention below the lifetime can only arrive through an environment variable: written, the container refuses
     * it. No carrier is asked, the method is not offered, and the store is told once, naming both settings.
     */
    #[DataProvider('unusableRateSettings')]
    public function testSettingsThatCannotBeUsedLeaveTheMethodUnavailableWithoutAskingTheCarrier(CarrierSettingsProvider $settings, string $named): void
    {
        $provider = $this->provider(settings: $settings);

        self::assertEquals(RateResult::unavailable(), $provider->rateFor($this->shipment(), 'ups', '03'));
        self::assertEquals(RateResult::unavailable(), $provider->rateFor($this->shipment(), 'ups', '02'));
        self::assertSame([], $this->ups->requests);

        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0][0]);
        $reason = $this->logger->records[0][2]['reason'] ?? null;
        self::assertIsString($reason);
        self::assertStringContainsString($named, $reason);
    }

    /**
     * @return iterable<string, array{CarrierSettingsProvider, string}>
     */
    public static function unusableRateSettings(): iterable
    {
        yield 'retention below the lifetime' => [CarrierSettingsFactory::provider(rateLifetime: 900, rateRetention: 600), 'rate_retention is 600 and rate_lifetime is 900'];
        yield 'lifetime of zero' => [CarrierSettingsFactory::provider(rateLifetime: 0), 'rate_lifetime is 0'];
        yield 'timeout of zero' => [CarrierSettingsFactory::provider(carrierTimeout: 0.0), 'carrier_timeout is 0'];
    }

    public function testTheLifetimeAndTheRetentionAreTheConfiguredOnes(): void
    {
        $this->provider(lifetime: 60, retention: 120)->rateFor($this->shipment(), 'ups', '03');

        $this->clock->sleep(60);
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');
        self::assertSame(1540, $this->provider(lifetime: 60, retention: 120)->rateFor($this->shipment(), 'ups', '03')->lastKnownRate?->amount);

        $this->clock->sleep(60);
        self::assertNull($this->provider(lifetime: 60, retention: 120)->rateFor($this->shipment(), 'ups', '03')->lastKnownRate);
    }

    /**
     * A carrier that is down costs a single wait, not one per service.
     */
    public function testWithTheCarrierDownEveryServiceFailsAfterASingleCall(): void
    {
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');
        $provider = $this->provider();
        $shipment = $this->shipment();

        $failed = array_map(
            static fn (string $service): bool => $provider->rateFor($shipment, 'ups', $service)->carrierFailed,
            ['03', '02', '01'],
        );

        self::assertSame([true, true, true], $failed);
        self::assertCount(1, $this->ups->requests);
    }

    /**
     * A failure is remembered for one request: the next one asks the carrier again.
     */
    public function testAResetForgetsTheFailureOfTheCarrier(): void
    {
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');
        $provider = $this->provider();
        $provider->rateFor($this->shipment(), 'ups', '03');

        $provider->reset();
        $provider->rateFor($this->shipment(), 'ups', '03');

        self::assertCount(2, $this->ups->requests);
    }

    /**
     * Not offering a service somewhere is an answer of the carrier, not a failure.
     */
    public function testAServiceTheCarrierDoesNotOfferForTheShipmentIsUnavailable(): void
    {
        self::assertEquals(RateResult::unavailable(), $this->provider()->rateFor($this->shipment(), 'ups', '14'));
    }

    public function testAnOrderWithoutAShippingAddressDoesNotCallTheCarrier(): void
    {
        $shipment = $this->shipment();
        $shipment->getOrder()?->setShippingAddress(null);

        self::assertEquals(RateResult::unavailable(), $this->provider()->rateFor($shipment, 'ups', '03'));
        self::assertCount(0, $this->ups->requests);
    }

    public function testAChannelWithoutAnOriginDoesNotCallTheCarrier(): void
    {
        $this->origin = null;

        self::assertEquals(RateResult::unavailable(), $this->provider()->rateFor($this->shipment(), 'ups', '03'));
        self::assertCount(0, $this->ups->requests);
    }

    public function testAShipmentThatCannotBePackedDoesNotCallTheCarrier(): void
    {
        $this->unpackable = true;

        self::assertEquals(RateResult::unavailable(), $this->provider()->rateFor($this->shipment(), 'ups', '03'));
        self::assertCount(0, $this->ups->requests);
    }

    public function testMissingCredentialsAreAFailureOfTheCarrier(): void
    {
        $this->credentials = null;

        self::assertEquals(RateResult::carrierFailed(null), $this->provider()->rateFor($this->shipment(), 'ups', '03'));
        self::assertCount(0, $this->ups->requests);
    }

    public function testARateInAnotherCurrencyIsQuotedConverted(): void
    {
        $this->exchangeRate = $this->exchangeRate('USD', 'EUR', 0.9);

        $rate = $this->provider()->rateFor($this->shipment(currencyCode: 'EUR'), 'ups', '03')->rate;

        self::assertEquals(new Rate('03', 1386, 'EUR'), $rate);
    }

    public function testTheLastKnownRateIsConvertedToo(): void
    {
        $this->exchangeRate = $this->exchangeRate('USD', 'EUR', 0.9);
        $this->provider()->rateFor($this->shipment(currencyCode: 'EUR'), 'ups', '03');

        $this->clock->sleep(self::LIFETIME);
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');

        self::assertEquals(new Rate('03', 1386, 'EUR'), $this->provider()->rateFor($this->shipment(currencyCode: 'EUR'), 'ups', '03')->lastKnownRate);
    }

    /**
     * The one place where two criteria pull apart: the carrier is down, there is a last known rate, and it is
     * in a currency nobody set a rate for. It cannot be charged as it stands, so what is left is the policy
     * the merchant chose for exactly this — and the resolver can only see that by getting no rate back.
     */
    public function testALastKnownRateThatCannotBeConvertedLeavesTheFailurePolicyInCharge(): void
    {
        $this->exchangeRate = $this->exchangeRate('USD', 'EUR', 0.9);
        $this->provider()->rateFor($this->shipment(currencyCode: 'EUR'), 'ups', '03');

        // The rate is stored in dollars; by the time the carrier falls over, the pair has no rate any more.
        $this->clock->sleep(self::LIFETIME);
        $this->exchangeRate = null;
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');

        $result = $this->provider()->rateFor($this->shipment(currencyCode: 'EUR'), 'ups', '03');

        self::assertTrue($result->carrierFailed);
        self::assertNull($result->lastKnownRate, 'A rate that cannot be converted must not reach the resolver.');

        // Nothing is charged silently: the reason it could not be converted is on record.
        $conversion = array_values(array_filter(
            $this->logger->records,
            static fn (array $record): bool => isset($record[2]['rate_currency']),
        ));
        self::assertCount(1, $conversion);
        self::assertSame(LogLevel::ERROR, $conversion[0][0]);
        self::assertSame(['service' => '03', 'rate_currency' => 'USD', 'order_currency' => 'EUR'], $conversion[0][2]);
    }

    /**
     * Charging the dollars as the same number of euros is never an option.
     */
    public function testARateInACurrencyWithoutAnExchangeRateIsUnavailableAndLogged(): void
    {
        self::assertEquals(RateResult::unavailable(), $this->provider()->rateFor($this->shipment(currencyCode: 'EUR'), 'ups', '03'));

        self::assertCount(1, $this->logger->records);
        [$level, , $context] = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertSame(['service' => '03', 'rate_currency' => 'USD', 'order_currency' => 'EUR'], $context);
    }

    /**
     * What the cache holds under a key may come from another version of the plugin.
     */
    public function testAnUnreadableCacheEntryAsksTheCarrierAgain(): void
    {
        $this->provider()->rateFor($this->shipment(), 'ups', '03');
        foreach (array_keys($this->cache->getValues()) as $key) {
            $this->cache->save($this->cache->getItem($key)->set(['fetched_at' => 'yesterday']));
        }

        $result = $this->provider()->rateFor($this->shipment(), 'ups', '03');

        self::assertSame(1540, $result->rate?->amount);
        self::assertCount(2, $this->ups->requests);
    }

    /**
     * A failure the store never sees on the page has to be findable in the log.
     */
    public function testACarrierFailureIsLoggedWithTheCarrierTheServiceAndTheCause(): void
    {
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');

        $this->provider()->rateFor($this->shipment(), 'ups', '03');

        self::assertCount(1, $this->logger->records);
        [$level, , $context] = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertSame('ups', $context['carrier'] ?? null);
        self::assertSame('03', $context['service'] ?? null);
        self::assertSame('UPS did not answer in time.', $context['reason'] ?? null);
    }

    /**
     * The shipping step asks once per service: without the memory of the failure, a carrier that is down would
     * write one entry per service and visit.
     */
    public function testAFailureRememberedForTheRequestIsLoggedOnce(): void
    {
        $this->ups->answer = new CarrierUnavailableException('UPS did not answer in time.');
        $provider = $this->provider();
        $shipment = $this->shipment();

        $provider->rateFor($shipment, 'ups', '03');
        $provider->rateFor($shipment, 'ups', '02');

        self::assertCount(1, $this->logger->records);
    }

    public function testCredentialsThatCannotBeReadAreLoggedOncePerRequest(): void
    {
        $this->credentials = null;
        $provider = $this->provider();
        $shipment = $this->shipment();

        $provider->rateFor($shipment, 'ups', '03');
        $provider->rateFor($shipment, 'ups', '02');

        self::assertCount(1, $this->logger->records);
        [$level, , $context] = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertSame('ups', $context['carrier'] ?? null);
        self::assertSame('03', $context['service'] ?? null);
        self::assertNotEmpty($context['reason'] ?? null);

        $provider->reset();
        $provider->rateFor($shipment, 'ups', '03');
        self::assertCount(2, $this->logger->records);
    }

    public function testAnUnknownCarrierIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider()->rateFor($this->shipment(), 'dhl', '03');
    }

    private function provider(int $lifetime = self::LIFETIME, int $retention = self::RETENTION, ?CarrierSettingsProvider $settings = null): RateProvider
    {
        /** @var RepositoryInterface<CarrierShippingOriginInterface>&Stub $originRepository */
        $originRepository = $this->createStub(RepositoryInterface::class);
        $originRepository->method('findOneBy')->willReturnCallback(fn (): ?CarrierShippingOrigin => $this->origin);

        $packagingStrategy = $this->createStub(PackagingStrategyInterface::class);
        $packagingStrategy->method('pack')->willReturnCallback(fn (): array => $this->unpackable
            ? throw new UnpackableShipmentException('The variant "MUG" has no weight declared.')
            : $this->packages);

        $destinationTypeResolver = $this->createStub(DestinationTypeResolverInterface::class);
        $destinationTypeResolver->method('resolve')->willReturnCallback(fn (): string => $this->destinationType);

        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $credentialsRepository */
        $credentialsRepository = $this->createStub(RepositoryInterface::class);
        $credentialsRepository->method('findOneBy')->willReturnCallback(fn (): ?CarrierCredentials => $this->credentials);

        $exchangeRateRepository = $this->createStub(ExchangeRateRepositoryInterface::class);
        $exchangeRateRepository->method('findOneWithCurrencyPair')->willReturnCallback(fn (): ?ExchangeRateInterface => $this->exchangeRate);

        return new RateProvider(
            new RateRequestFactory($originRepository, $packagingStrategy, $destinationTypeResolver, new AddressFactory(), $this->logger),
            new CredentialsProvider($credentialsRepository),
            new ServiceLocator(['ups' => fn (): RecordingCarrier => $this->ups]),
            $this->cache,
            new RateCurrencyConverter($exchangeRateRepository, new CurrencyConverter($exchangeRateRepository), $this->logger),
            $this->clock,
            $this->logger,
            $settings ?? CarrierSettingsFactory::provider(rateLifetime: $lifetime, rateRetention: $retention),
        );
    }

    private function shipment(string $postcode = '98101', string $currencyCode = 'USD'): Shipment
    {
        $channel = new Channel();
        $channel->setCode('WEB');

        $address = new Address();
        $address->setStreet('500 Pine St');
        $address->setCity('Seattle');
        $address->setPostcode($postcode);
        $address->setCountryCode('US');
        $address->setProvinceCode('US-WA');

        $order = new Order();
        $order->setChannel($channel);
        $order->setCurrencyCode($currencyCode);
        $order->setShippingAddress($address);

        $shipment = new Shipment();
        $order->addShipment($shipment);

        return $shipment;
    }

    private function exchangeRate(string $source, string $target, float $ratio): ExchangeRate
    {
        $sourceCurrency = new Currency();
        $sourceCurrency->setCode($source);
        $targetCurrency = new Currency();
        $targetCurrency->setCode($target);

        $exchangeRate = new ExchangeRate();
        $exchangeRate->setSourceCurrency($sourceCurrency);
        $exchangeRate->setTargetCurrency($targetCurrency);
        $exchangeRate->setRatio($ratio);

        return $exchangeRate;
    }
}
