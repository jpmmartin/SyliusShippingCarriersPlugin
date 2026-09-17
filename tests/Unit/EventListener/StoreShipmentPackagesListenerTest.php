<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\EventListener;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackageInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackaging;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackagingInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\EventListener\StoreShipmentPackagesListener;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\PackagingStrategyInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Registry\ServiceRegistry;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Calculator\FlatRateCalculator;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\Factory;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

final class StoreShipmentPackagesListenerTest extends TestCase
{
    /** @var list<CarrierShipmentPackagingInterface> */
    private array $persisted = [];

    private RecordingLogger $logger;

    private ?CarrierShippingOrigin $origin;

    /** @var non-empty-list<Package>|UnpackableShipmentException */
    private array|UnpackableShipmentException $packing;

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->logger = new RecordingLogger();
        $this->origin = new CarrierShippingOrigin();
        $this->packing = [new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', [])];
    }

    public function testThePackagesOfACarrierShipmentAreStoredAsTheyWereDeclared(): void
    {
        $unit = new OrderItemUnit(new OrderItem());
        $this->packing = [
            new Package('Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', [$unit]),
            new Package(null, 20.0, 11.0, 9.0, 'in', 9.5, 'lb', []),
        ];

        ($this->listener())($this->orderConfirmed('ups_rate'));

        self::assertCount(1, $this->persisted);
        $packaging = $this->persisted[0];
        self::assertSame(CarrierShipmentPackagingInterface::STATE_PERSISTED, $packaging->getState());
        self::assertCount(2, $packaging->getPackages());

        $first = $packaging->getPackages()->first();
        self::assertInstanceOf(CarrierShipmentPackage::class, $first);
        self::assertSame(['Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', 0], [
            $first->getBoxName(), $first->getLength(), $first->getWidth(), $first->getHeight(),
            $first->getDimensionUnit(), $first->getWeight(), $first->getWeightUnit(), $first->getPosition(),
        ]);
        self::assertSame([$unit], array_values($first->getUnits()->toArray()));
    }

    /**
     * A shipment with no packages and no reason would look like a shipment with nothing in it.
     */
    public function testAShipmentThatCannotBePackedIsStoredAsAFailureAndTheOrderIsStillConfirmed(): void
    {
        $this->packing = new UnpackableShipmentException('The variant "MUG" has no weight declared.');

        ($this->listener())($this->orderConfirmed('ups_rate'));

        self::assertCount(1, $this->persisted);
        $packaging = $this->persisted[0];
        self::assertSame(CarrierShipmentPackagingInterface::STATE_FAILED, $packaging->getState());
        self::assertSame('The variant "MUG" has no weight declared.', $packaging->getFailureReason());
        self::assertCount(0, $packaging->getPackages());
        self::assertSame(LogLevel::ERROR, $this->logger->records[0][0] ?? null);
    }

    public function testAChannelWithoutAnOriginIsStoredAsAFailure(): void
    {
        $this->origin = null;

        ($this->listener())($this->orderConfirmed('ups_rate'));

        self::assertCount(1, $this->persisted);
        self::assertSame(CarrierShipmentPackagingInterface::STATE_FAILED, $this->persisted[0]->getState());
        self::assertStringContainsString('no shipping origin', (string) $this->persisted[0]->getFailureReason());
    }

    public function testAShipmentOfAnotherCalculatorIsLeftAlone(): void
    {
        ($this->listener())($this->orderConfirmed('flat_rate'));

        self::assertSame([], $this->persisted);
    }

    private function listener(): StoreShipmentPackagesListener
    {
        $calculators = new ServiceRegistry(CalculatorInterface::class);
        $chargeResolver = new ShippingChargeResolver($this->createStub(RateProviderInterface::class));
        $calculators->register('ups_rate', new CarrierRateCalculator($chargeResolver, 'ups', 'ups_rate'));
        $calculators->register('flat_rate', new FlatRateCalculator());

        $packagingStrategy = $this->createStub(PackagingStrategyInterface::class);
        $packagingStrategy->method('pack')->willReturnCallback(fn (): array => $this->packing instanceof UnpackableShipmentException ? throw $this->packing : $this->packing);

        /** @var RepositoryInterface<CarrierShippingOriginInterface>&Stub $originRepository */
        $originRepository = $this->createStub(RepositoryInterface::class);
        $originRepository->method('findOneBy')->willReturnCallback(fn (): ?CarrierShippingOrigin => $this->origin);

        $manager = $this->createMock(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(function (object $packaging): void {
            self::assertInstanceOf(CarrierShipmentPackagingInterface::class, $packaging);
            $this->persisted[] = $packaging;
        });

        /** @var FactoryInterface<CarrierShipmentPackagingInterface> $packagingFactory */
        $packagingFactory = new Factory(CarrierShipmentPackaging::class);
        /** @var FactoryInterface<CarrierShipmentPackageInterface> $packageFactory */
        $packageFactory = new Factory(CarrierShipmentPackage::class);

        return new StoreShipmentPackagesListener(
            $calculators,
            $packagingStrategy,
            $originRepository,
            $packagingFactory,
            $packageFactory,
            $manager,
            $this->logger,
        );
    }

    private function orderConfirmed(string $calculator): Event
    {
        $channel = new Channel();
        $channel->setCode('WEB');

        $method = new ShippingMethod();
        $method->setCalculator($calculator);

        $order = new Order();
        $order->setChannel($channel);
        $shipment = new Shipment();
        $shipment->setMethod($method);
        $order->addShipment($shipment);

        return new Event($order, new Marking(), new Transition('complete', 'payment_selected', 'completed'), $this->createStub(Workflow::class));
    }
}
