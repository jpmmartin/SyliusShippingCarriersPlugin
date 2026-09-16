<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Entity;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackageInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackaging;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackagingInterface;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Shipping\Model\ShipmentUnitInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CarrierShipmentPackagingTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * Base of CA-42: each package keeps the values it was declared with and the units it carries.
     */
    public function testItStoresAPackagingWithTwoPackagesAndTheirUnits(): void
    {
        $shipment = $this->createShipment(3);
        [$first, $second, $third] = $shipment->getUnits()->getValues();

        $packaging = new CarrierShipmentPackaging();
        $packaging->setShipment($shipment);
        $packaging->addPackage($this->package('Medium', [13.0, 11.0, 9.0], 5.5, $first, $second));
        $packaging->addPackage($this->package(null, [10.0, 10.0, 20.0], 3.25, $third));
        $this->entityManager->persist($packaging);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $found = $this->entityManager->find(CarrierShipmentPackaging::class, $packaging->getId());
        self::assertInstanceOf(CarrierShipmentPackaging::class, $found);
        self::assertSame(CarrierShipmentPackagingInterface::STATE_PERSISTED, $found->getState());
        self::assertNull($found->getFailureReason());
        self::assertSame($shipment->getId(), $found->getShipment()?->getId());

        self::assertSame([
            [0, 'Medium', 13.0, 11.0, 9.0, 'in', 5.5, 'lb', [$first->getId(), $second->getId()]],
            [1, null, 10.0, 10.0, 20.0, 'in', 3.25, 'lb', [$third->getId()]],
        ], array_map($this->describe(...), $found->getPackages()->getValues()));
    }

    /**
     * Base of CA-45: a packaging that could not be stored keeps why, and has no packages.
     */
    public function testItStoresAFailedPackagingWithItsReason(): void
    {
        $packaging = new CarrierShipmentPackaging();
        $packaging->setShipment($this->createShipment(1));
        $packaging->fail('The variant "feather" has no weight declared.');
        $this->entityManager->persist($packaging);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $found = $this->entityManager->find(CarrierShipmentPackaging::class, $packaging->getId());
        self::assertInstanceOf(CarrierShipmentPackaging::class, $found);
        self::assertSame(CarrierShipmentPackagingInterface::STATE_FAILED, $found->getState());
        self::assertSame('The variant "feather" has no weight declared.', $found->getFailureReason());
        self::assertCount(0, $found->getPackages());
    }

    public function testASecondPackagingForTheSameShipmentIsRejected(): void
    {
        $shipment = $this->createShipment(1);

        foreach ([1, 2] as $attempt) {
            $packaging = new CarrierShipmentPackaging();
            $packaging->setShipment($shipment);
            $packaging->fail(sprintf('Attempt %d.', $attempt));
            $this->entityManager->persist($packaging);
        }

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testAUnitCannotTravelInTwoPackages(): void
    {
        $shipment = $this->createShipment(1);
        $unit = $shipment->getUnits()->first();
        self::assertInstanceOf(ShipmentUnitInterface::class, $unit);

        $packaging = new CarrierShipmentPackaging();
        $packaging->setShipment($shipment);
        $packaging->addPackage($this->package('Small', [5.0, 5.0, 5.0], 1.0, $unit));
        $packaging->addPackage($this->package('Small', [5.0, 5.0, 5.0], 1.0, $unit));
        $this->entityManager->persist($packaging);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    /**
     * An order in the cart with one shipment that carries `$units` units of a single variant.
     */
    private function createShipment(int $units): Shipment
    {
        $zone = new Zone();
        $zone->setCode('packaging-zone');
        $zone->setName('Packaging zone');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);

        $method = new ShippingMethod();
        $method->setCode('packaging-method');
        $method->setCalculator('flat_rate');
        $method->setZone($zone);

        $variant = new ProductVariant();
        $variant->setCode('packaging-variant');
        $product = new Product();
        $product->setCode('packaging-product');
        $product->addVariant($variant);

        $order = new Order();
        $order->setCurrencyCode('EUR');
        $order->setLocaleCode('en_US');
        $item = new OrderItem();
        $item->setVariant($variant);
        $order->addItem($item);

        $shipment = new Shipment();
        $shipment->setMethod($method);
        $order->addShipment($shipment);
        for ($i = 0; $i < $units; ++$i) {
            $shipment->addUnit(new OrderItemUnit($item));
        }

        $this->entityManager->persist($zone);
        $this->entityManager->persist($method);
        $this->entityManager->persist($product);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $shipment;
    }

    /**
     * @param array{float, float, float} $measures
     */
    private function package(?string $boxName, array $measures, float $weight, ShipmentUnitInterface ...$units): CarrierShipmentPackage
    {
        $package = new CarrierShipmentPackage();
        $package->setBoxName($boxName);
        $package->setLength($measures[0]);
        $package->setWidth($measures[1]);
        $package->setHeight($measures[2]);
        $package->setDimensionUnit('in');
        $package->setWeight($weight);
        $package->setWeightUnit('lb');
        foreach ($units as $unit) {
            $package->addUnit($unit);
        }

        return $package;
    }

    /**
     * @return array{int, string|null, float|null, float|null, float|null, string|null, float|null, string|null, list<mixed>}
     */
    private function describe(CarrierShipmentPackageInterface $package): array
    {
        return [
            $package->getPosition(),
            $package->getBoxName(),
            $package->getLength(),
            $package->getWidth(),
            $package->getHeight(),
            $package->getDimensionUnit(),
            $package->getWeight(),
            $package->getWeightUnit(),
            array_values(array_map(static fn (ShipmentUnitInterface $unit): mixed => $unit->getId(), $package->getUnits()->toArray())),
        ];
    }
}
