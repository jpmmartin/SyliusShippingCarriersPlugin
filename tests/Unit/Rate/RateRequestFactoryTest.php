<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Rate;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address as CarrierAddress;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\AddressFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationTypeResolverInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\PackagingStrategyInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateRequestFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

final class RateRequestFactoryTest extends TestCase
{
    private ?CarrierShippingOrigin $origin;

    /** @var array<string, CarrierShippingOrigin> An origin of its own per channel code, when a test sets them. */
    private array $origins = [];

    private string $destinationType = DestinationType::COMMERCIAL;

    private PackagingStrategyInterface&MockObject $packagingStrategy;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();

        $this->origin = new CarrierShippingOrigin();
        $this->origin->setStreet('1 Main St');
        $this->origin->setCity('Chicago');
        $this->origin->setPostcode('60601');
        $this->origin->setCountryCode('US');
        $this->origin->setProvinceCode('IL');

        $this->packagingStrategy = $this->createMock(PackagingStrategyInterface::class);
    }

    /**
     * Sylius codes the province of an address with its country in front; carriers take the subdivision alone.
     */
    public function testTheShipmentIsRatedFromTheOriginOfItsChannelToTheShippingAddressOfItsOrder(): void
    {
        $packages = [new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', [])];
        $this->packagingStrategy->method('pack')->willReturn($packages);

        $request = $this->factory()->create($this->shipment());

        self::assertEquals(new CarrierAddress('US', '60601', 'Chicago', '1 Main St', 'IL'), $request?->origin);
        self::assertEquals(new CarrierAddress('US', '98101', 'Seattle', '500 Pine St', 'WA'), $request?->destination);
        self::assertSame($packages, $request?->packages);
    }

    /**
     * With one origin in the store, «the origin of its channel» and «the only origin» are the same sentence.
     * A store that ships from two places is where they part: each channel quotes from its own, and getting
     * this wrong would quote every parcel from the wrong warehouse without anything looking broken.
     */
    public function testEachChannelIsRatedFromItsOwnOrigin(): void
    {
        $this->packagingStrategy->method('pack')->willReturn([new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', [])]);
        $this->origins = [
            'WEB' => $this->origin(street: '1 Main St', city: 'Chicago', postcode: '60601', province: 'IL'),
            'WEB_EU' => $this->origin(street: '10 Rue de Rivoli', city: 'Paris', postcode: '75001', province: null),
        ];

        self::assertEquals(
            new CarrierAddress('US', '60601', 'Chicago', '1 Main St', 'IL'),
            $this->factory()->create($this->shipment('WEB'))?->origin,
        );
        self::assertEquals(
            new CarrierAddress('US', '75001', 'Paris', '10 Rue de Rivoli'),
            $this->factory()->create($this->shipment('WEB_EU'))?->origin,
        );
    }

    private function origin(string $street, string $city, string $postcode, ?string $province): CarrierShippingOrigin
    {
        $origin = new CarrierShippingOrigin();
        $origin->setStreet($street);
        $origin->setCity($city);
        $origin->setPostcode($postcode);
        $origin->setCountryCode('US');
        $origin->setProvinceCode($province);

        return $origin;
    }

    /**
     * The origin's province is typed by hand.
     */
    public function testAnOriginProvinceWrittenWithItsCountryIsSentWithoutIt(): void
    {
        $this->origin?->setProvinceCode('US-IL');
        $this->packagingStrategy->method('pack')->willReturn([new Package(null, 1.0, 1.0, 1.0, 'in', 1.0, 'lb', [])]);

        self::assertSame('IL', $this->factory()->create($this->shipment())?->origin->provinceCode);
    }

    public function testTheDestinationIsAHomeWhenTheOrderSaysSo(): void
    {
        $this->packagingStrategy->method('pack')->willReturn([new Package(null, 1.0, 1.0, 1.0, 'in', 1.0, 'lb', [])]);

        self::assertFalse($this->factory()->create($this->shipment())?->destination->residential);

        $this->destinationType = DestinationType::RESIDENTIAL;
        self::assertTrue($this->factory()->create($this->shipment())?->destination->residential);
    }

    /**
     * Without an address nothing is rated, so nothing is packed either.
     */
    public function testAnOrderWithoutAShippingAddressIsNotRated(): void
    {
        $shipment = $this->shipment();
        $shipment->getOrder()?->setShippingAddress(null);

        $this->packagingStrategy->expects(self::never())->method('pack');

        self::assertNull($this->factory()->create($shipment));
    }

    #[DataProvider('incompleteAddresses')]
    public function testAnIncompleteShippingAddressIsNotRated(string $field): void
    {
        $shipment = $this->shipment();
        $address = $shipment->getOrder()?->getShippingAddress();
        self::assertInstanceOf(Address::class, $address);
        match ($field) {
            'country' => $address->setCountryCode(null),
            'postcode' => $address->setPostcode(' '),
            'city' => $address->setCity(null),
            'street' => $address->setStreet(''),
            default => self::fail(sprintf('Unknown field "%s".', $field)),
        };

        $this->packagingStrategy->expects(self::never())->method('pack');

        self::assertNull($this->factory()->create($shipment));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function incompleteAddresses(): iterable
    {
        yield 'without a country' => ['country'];
        yield 'with a blank postcode' => ['postcode'];
        yield 'without a city' => ['city'];
        yield 'with an empty street' => ['street'];
    }

    public function testAChannelWithoutAnOriginIsNotRatedAndIsLogged(): void
    {
        $this->origin = null;

        self::assertNull($this->factory()->create($this->shipment()));

        self::assertCount(1, $this->logger->records);
        [$level, $message, $context] = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertStringContainsString('no shipping origin', $message);
        self::assertSame(['channel' => 'WEB'], $context);
    }

    /**
     * The shipping step asks once per shipping method; the missing origin is logged once per request.
     */
    public function testAChannelWithoutAnOriginIsLoggedOncePerRequest(): void
    {
        $this->origin = null;
        $factory = $this->factory();

        $factory->create($this->shipment());
        $factory->create($this->shipment());
        self::assertCount(1, $this->logger->records);

        $factory->reset();
        $factory->create($this->shipment());
        self::assertCount(2, $this->logger->records);
    }

    /**
     * An origin missing a part a carrier requires leaves the channel as unable to quote as having no origin at
     * all, and from the outside it looks the same: the method simply is not offered. So it gets the same shout,
     * naming what is missing — otherwise nobody can tell which of the two happened.
     *
     * @param \Closure(CarrierShippingOriginInterface): void $emptyIt
     */
    #[DataProvider('incompleteOrigins')]
    public function testAnOriginMissingWhatACarrierRequiresIsNotRatedAndIsLogged(\Closure $emptyIt, string $missing): void
    {
        self::assertInstanceOf(CarrierShippingOriginInterface::class, $this->origin);
        $emptyIt($this->origin);

        self::assertNull($this->factory()->create($this->shipment()));

        self::assertCount(1, $this->logger->records);
        [$level, $message, $context] = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertStringContainsString('has no {missing}', $message);
        self::assertSame(['channel' => 'WEB', 'missing' => $missing], $context);
    }

    /**
     * @return iterable<string, array{\Closure(CarrierShippingOriginInterface): void, string}>
     */
    public static function incompleteOrigins(): iterable
    {
        yield 'without a street' => [static fn (CarrierShippingOriginInterface $o): null => $o->setStreet(null), 'street'];
        yield 'without a city' => [static fn (CarrierShippingOriginInterface $o): null => $o->setCity(null), 'city'];
        yield 'without a postcode' => [static fn (CarrierShippingOriginInterface $o): null => $o->setPostcode(null), 'postcode'];
        yield 'without a country' => [static fn (CarrierShippingOriginInterface $o): null => $o->setCountryCode(null), 'country'];
        // Blank is as useless at a carrier as absent, and it is what a spreadsheet import leaves behind.
        yield 'with a blank street' => [static fn (CarrierShippingOriginInterface $o): null => $o->setStreet('   '), 'street'];
        yield 'missing more than one' => [
            static function (CarrierShippingOriginInterface $o): void {
                $o->setCity(null);
                $o->setCountryCode(null);
            },
            'city, country',
        ];
    }

    /**
     * The shipping step asks once per shipping method, the same as with no origin at all.
     */
    public function testAnIncompleteOriginIsLoggedOncePerRequest(): void
    {
        $this->origin?->setPostcode(null);
        $factory = $this->factory();

        $factory->create($this->shipment());
        $factory->create($this->shipment());
        self::assertCount(1, $this->logger->records);

        $factory->reset();
        $factory->create($this->shipment());
        self::assertCount(2, $this->logger->records);
    }

    public function testAShipmentThatCannotBePackedIsNotRated(): void
    {
        $this->packagingStrategy->method('pack')->willThrowException(new UnpackableShipmentException('The variant "MUG" has no weight declared.'));

        self::assertNull($this->factory()->create($this->shipment()));
    }

    private function factory(): RateRequestFactory
    {
        /** @var RepositoryInterface<CarrierShippingOriginInterface>&Stub $originRepository */
        $originRepository = $this->createStub(RepositoryInterface::class);
        $originRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?CarrierShippingOrigin {
                $channel = $criteria['channel'] ?? null;
                $code = $channel instanceof ChannelInterface ? (string) $channel->getCode() : '';

                return $this->origins[$code] ?? $this->origin;
            },
        );

        $destinationTypeResolver = $this->createStub(DestinationTypeResolverInterface::class);
        $destinationTypeResolver->method('resolve')->willReturnCallback(fn (): string => $this->destinationType);

        return new RateRequestFactory($originRepository, $this->packagingStrategy, $destinationTypeResolver, new AddressFactory(), $this->logger);
    }

    private function shipment(string $channelCode = 'WEB'): Shipment
    {
        $channel = new Channel();
        $channel->setCode($channelCode);

        $address = new Address();
        $address->setStreet('500 Pine St');
        $address->setCity('Seattle');
        $address->setPostcode('98101');
        $address->setCountryCode('US');
        $address->setProvinceCode('US-WA');

        $order = new Order();
        $order->setChannel($channel);
        $order->setShippingAddress($address);

        $shipment = new Shipment();
        $order->addShipment($shipment);

        return $shipment;
    }
}
