<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Entity;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CarrierShipmentExportTest extends KernelTestCase
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
        // Everything each test writes is rolled back, so tests do not depend on each other's leftovers.
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * The shape of the thing: a shipment that went out in three packages has three labels, each with its own
     * tracking number, and they come back in the order they were packed.
     */
    public function testAShipmentOfThreePackagesKeepsThreeLabels(): void
    {
        $export = $this->export($this->createShipment());
        foreach (['1Z999AA1', '1Z999AA2', '1Z999AA3'] as $position => $trackingNumber) {
            $export->addLabel($this->label($position, $trackingNumber));
        }

        $this->entityManager->persist($export);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->entityManager->getRepository(CarrierShipmentExport::class)->find((int) $export->getId());
        self::assertInstanceOf(CarrierShipmentExport::class, $stored);
        self::assertCount(3, $stored->getLabels());
        self::assertSame(
            ['1Z999AA1', '1Z999AA2', '1Z999AA3'],
            $stored->getLabels()->map(static fn (CarrierShipmentLabelInterface $label): string => (string) $label->getTrackingNumber())->toArray(),
        );
    }

    public function testItStartsPendingAndKeepsWhatTheCarrierAnswered(): void
    {
        $export = new CarrierShipmentExport();
        self::assertSame(CarrierShipmentExportInterface::STATE_PENDING, $export->getState());

        $export->setShipment($this->createShipment());
        $export->setState(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->setCarrier('ups');
        $export->setEnvironment('sandbox');
        $export->setCarrierReference('1Z999AA10123456784');
        $export->setIssuedAt(new \DateTimeImmutable('2026-09-18 10:15:00'));
        $export->setIssuedBy('warehouse@example.com');

        $this->entityManager->persist($export);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->entityManager->getRepository(CarrierShipmentExport::class)->find((int) $export->getId());
        self::assertInstanceOf(CarrierShipmentExport::class, $stored);
        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $stored->getState());
        self::assertSame('ups', $stored->getCarrier());
        self::assertSame('sandbox', $stored->getEnvironment());
        self::assertSame('1Z999AA10123456784', $stored->getCarrierReference());
        self::assertSame('2026-09-18 10:15:00', $stored->getIssuedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('warehouse@example.com', $stored->getIssuedBy());
    }

    public function testAShipmentIsExportedOnce(): void
    {
        $shipment = $this->createShipment();

        $this->entityManager->persist($this->export($shipment));
        $this->entityManager->persist($this->export($shipment));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    /**
     * What went out is not forgotten because the file was deleted: the row stays, and says when.
     */
    public function testPurgingTheFileLeavesTheLabelBehind(): void
    {
        $export = $this->export($this->createShipment());
        $label = $this->label(0, '1Z999AA1');
        $export->addLabel($label);
        $this->entityManager->persist($export);
        $this->entityManager->flush();

        $label->setPath(null);
        $label->setPurgedAt(new \DateTimeImmutable('2026-09-18 12:00:00'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->entityManager->getRepository(CarrierShipmentLabel::class)->find((int) $label->getId());
        self::assertInstanceOf(CarrierShipmentLabel::class, $stored);
        self::assertTrue($stored->isPurged());
        self::assertNull($stored->getPath());
        // Everything that says what went out is still there.
        self::assertSame('1Z999AA1', $stored->getTrackingNumber());
        self::assertSame(2500, $stored->getDeclaredValue());
        self::assertSame('USD', $stored->getDeclaredValueCurrency());
    }

    public function testTwoLabelsCannotBeTheSamePackageOfAnExport(): void
    {
        $export = $this->export($this->createShipment());
        $export->addLabel($this->label(0, '1Z999AA1'));
        $export->addLabel($this->label(0, '1Z999AA2'));

        $this->entityManager->persist($export);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    private function export(ShipmentInterface $shipment): CarrierShipmentExport
    {
        $export = new CarrierShipmentExport();
        $export->setShipment($shipment);
        $export->setCarrier('ups');
        $export->setEnvironment('sandbox');

        return $export;
    }

    private function label(int $position, string $trackingNumber): CarrierShipmentLabel
    {
        $label = new CarrierShipmentLabel();
        $label->setPosition($position);
        $label->setPath(sprintf('labels/%s.pdf', $trackingNumber));
        $label->setFormat('PDF');
        $label->setTrackingNumber($trackingNumber);
        $label->setDeclaredValue(2500);
        $label->setDeclaredValueCurrency('USD');

        return $label;
    }

    private function createShipment(): ShipmentInterface
    {
        $zone = new Zone();
        $zone->setCode('export-zone-' . bin2hex(random_bytes(4)));
        $zone->setName('Export zone');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);

        $method = new ShippingMethod();
        $method->setCode('export-method-' . bin2hex(random_bytes(4)));
        $method->setCalculator('flat_rate');
        $method->setZone($zone);

        // A shipment belongs to an order: sylius_shipment has it NOT NULL.
        $order = new Order();
        $order->setCurrencyCode('USD');
        $order->setLocaleCode('en_US');

        $shipment = new Shipment();
        $shipment->setMethod($method);
        $order->addShipment($shipment);

        $this->entityManager->persist($zone);
        $this->entityManager->persist($method);
        $this->entityManager->persist($order);
        $this->entityManager->persist($shipment);
        $this->entityManager->flush();

        return $shipment;
    }
}
