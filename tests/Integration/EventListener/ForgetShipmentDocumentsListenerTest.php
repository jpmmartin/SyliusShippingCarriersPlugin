<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use League\Flysystem\FilesystemOperator;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A deleted shipment must not leave its labels in the store. Its row goes by a cascade of the database, so if
 * nothing takes the files nothing ever will: no row is left naming them.
 */
final class ForgetShipmentDocumentsListenerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private FilesystemOperator $storage;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $storage = self::getContainer()->get('jpmmartin_carrier.storage.documents');
        self::assertInstanceOf(FilesystemOperator::class, $storage);
        $this->storage = $storage;

        $this->written = [];
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        foreach ($this->written as $path) {
            if ($this->storage->fileExists($path)) {
                $this->storage->delete($path);
            }
        }

        parent::tearDown();
    }

    public function testDeletingAShipmentTakesItsLabelsAndItsCustomsDocument(): void
    {
        $shipment = $this->createShipment();
        $export = $this->export($shipment);
        $labelPath = self::labelPath($export);
        $customsPath = (string) $export->getCustomsDocumentPath();
        $exportId = (int) $export->getId();

        $this->entityManager->remove($shipment);
        $this->entityManager->flush();

        self::assertFalse($this->storage->fileExists($labelPath));
        self::assertFalse($this->storage->fileExists($customsPath));
        // The export went with it, by the cascade of the database. Read again, not from what is in memory.
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(CarrierShipmentExport::class)->find($exportId));
    }

    /**
     * The files of one shipment are not the files of another, and a shop deletes shipments one at a time.
     */
    public function testDeletingAShipmentLeavesTheFilesOfEveryOtherAlone(): void
    {
        $doomed = $this->createShipment();
        $this->export($doomed);
        $kept = $this->export($this->createShipment());
        $keptPath = self::labelPath($kept);

        $this->entityManager->remove($doomed);
        $this->entityManager->flush();

        self::assertTrue($this->storage->fileExists($keptPath));
    }

    /**
     * Nothing is taken while the deletion is only scheduled. It is the most that can be promised: the files go
     * once the deletion is written, and a transaction opened above this one could still undo the row
     * afterwards, which no filesystem can be made to follow.
     */
    public function testAShipmentWhoseDeletionWasNeverWrittenKeepsItsFiles(): void
    {
        $shipment = $this->createShipment();
        $export = $this->export($shipment);
        $labelPath = self::labelPath($export);

        $this->entityManager->remove($shipment);

        self::assertTrue($this->storage->fileExists($labelPath));
    }

    /**
     * The listener carries what it has to do from one event to the next, and a note it does not throw away is
     * work it does again on the next flush of the request — over a directory that by then may hold something
     * else.
     */
    public function testItDoesNotTakeTheSameShipmentTwice(): void
    {
        $shipment = $this->createShipment();
        $export = $this->export($shipment);
        $labelPath = self::labelPath($export);

        $this->entityManager->remove($shipment);
        $this->entityManager->flush();
        self::assertFalse($this->storage->fileExists($labelPath));

        // Anything else written where that shipment kept its files, and any other flush of the same request.
        // Cleared first because the export was deleted by the database and Doctrine still holds it, pointing
        // at a shipment that is no longer there.
        $this->entityManager->clear();
        $this->write($labelPath, 'something else entirely');
        $this->createShipment();

        self::assertTrue($this->storage->fileExists($labelPath));
    }

    private static function labelPath(CarrierShipmentExportInterface $export): string
    {
        $labels = array_values($export->getLabels()->toArray());
        self::assertArrayHasKey(0, $labels);

        return (string) $labels[0]->getPath();
    }

    private function export(ShipmentInterface $shipment): CarrierShipmentExportInterface
    {
        $reference = '1Z' . bin2hex(random_bytes(4));

        $export = new CarrierShipmentExport();
        $export->setShipment($shipment);
        $export->setState(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->setCarrier('ups');
        $export->setCarrierReference($reference);
        $export->setIssuedAt(new \DateTimeImmutable('2026-09-22 09:00:00'));
        $export->setIssuedBy('warehouse@example.com');

        $label = new CarrierShipmentLabel();
        $label->setPosition(0);
        $label->setPath($this->write(sprintf('labels/%d/%s-0.gif', $shipment->getId(), $reference), 'the label'));
        $label->setFormat('GIF');
        $label->setTrackingNumber($reference);
        $export->addLabel($label);

        $export->setCustomsDocumentPath($this->write(sprintf('labels/%d/customs-%s.pdf', $shipment->getId(), $reference), 'the invoice'));
        $export->setCustomsDocumentFormat('PDF');

        $this->entityManager->persist($export);
        $this->entityManager->flush();

        return $export;
    }

    private function write(string $path, string $contents): string
    {
        $this->storage->write($path, $contents);
        $this->written[] = $path;

        return $path;
    }

    private function createShipment(): ShipmentInterface
    {
        $zone = new Zone();
        $zone->setCode('forget-zone-' . bin2hex(random_bytes(4)));
        $zone->setName('Forget zone');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);

        $method = new ShippingMethod();
        $method->setCode('forget-method-' . bin2hex(random_bytes(4)));
        $method->setCalculator('flat_rate');
        $method->setZone($zone);

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
