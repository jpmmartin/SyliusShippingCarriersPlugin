<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Repository;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierShipmentExportRepositoryInterface;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The query that decides what the purge deletes. It is written in DQL, so the only place it can be shown to
 * mean what it says is against a database: whether a shipment still keeps a file is a condition over its
 * labels, and getting it wrong either deletes what is still needed or never deletes anything.
 */
final class CarrierShipmentExportRepositoryTest extends KernelTestCase
{
    private const MOMENT = '2026-09-22 10:00:00';

    private EntityManagerInterface $entityManager;

    /** @var CarrierShipmentExportRepositoryInterface<CarrierShipmentExportInterface> */
    private CarrierShipmentExportRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $repository = self::getContainer()->get('jpmmartin_carrier.repository.shipment_export');
        self::assertInstanceOf(CarrierShipmentExportRepositoryInterface::class, $repository);
        $this->repository = $repository;

        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testAShipmentIssuedBeforeTheMomentThatStillKeepsALabelIsFound(): void
    {
        $export = $this->export('2026-09-22 09:59:59', ['1Z9991'], null);

        self::assertSame([$export->getId()], $this->idsFoundBefore(self::MOMENT));
    }

    public function testAShipmentIssuedAfterTheMomentIsLeftAlone(): void
    {
        $this->export('2026-09-22 10:00:01', ['1Z9991'], null);

        self::assertSame([], $this->idsFoundBefore(self::MOMENT));
    }

    /**
     * The boundary belongs to the shipment: at the very moment the retention runs out it is still kept, and
     * only afterwards is it gone.
     */
    public function testAShipmentIssuedAtTheMomentItselfIsLeftAlone(): void
    {
        $this->export(self::MOMENT, ['1Z9991'], null);

        self::assertSame([], $this->idsFoundBefore(self::MOMENT));
    }

    /**
     * Without this the purge would keep finding the same shipments every night and rewriting rows that have
     * nothing left to delete.
     */
    public function testAShipmentWhoseFilesAreAllPurgedIsNotFoundAgain(): void
    {
        $export = $this->export('2026-09-22 09:00:00', ['1Z9991', '1Z9992'], null);
        foreach ($export->getLabels() as $label) {
            $label->setPurgedAt(new \DateTimeImmutable('2026-09-22 09:30:00'));
        }
        $this->entityManager->flush();

        self::assertSame([], $this->idsFoundBefore(self::MOMENT));
    }

    public function testAShipmentIsFoundWhileOneOfItsLabelsIsStillStored(): void
    {
        $export = $this->export('2026-09-22 09:00:00', ['1Z9991', '1Z9992'], null);
        self::firstLabel($export)->setPurgedAt(new \DateTimeImmutable('2026-09-22 09:30:00'));
        $this->entityManager->flush();

        self::assertSame([$export->getId()], $this->idsFoundBefore(self::MOMENT));
    }

    /**
     * The customs document is not a label, so a shipment whose labels are all gone is still found while its
     * invoice is stored: that file holds an address too.
     */
    public function testAShipmentIsFoundForItsCustomsDocumentAlone(): void
    {
        $export = $this->export('2026-09-22 09:00:00', ['1Z9991'], 'labels/1/customs.pdf');
        self::firstLabel($export)->setPurgedAt(new \DateTimeImmutable('2026-09-22 09:30:00'));
        $this->entityManager->flush();

        self::assertSame([$export->getId()], $this->idsFoundBefore(self::MOMENT));
    }

    public function testAShipmentWhoseCustomsDocumentIsAlreadyPurgedIsNotFoundForIt(): void
    {
        $export = $this->export('2026-09-22 09:00:00', ['1Z9991'], 'labels/1/customs.pdf');
        self::firstLabel($export)->setPurgedAt(new \DateTimeImmutable('2026-09-22 09:30:00'));
        $export->setCustomsDocumentPurgedAt(new \DateTimeImmutable('2026-09-22 09:30:00'));
        $this->entityManager->flush();

        self::assertSame([], $this->idsFoundBefore(self::MOMENT));
    }

    /**
     * A shipment that was never issued has no file and no moment to count from. Reading it as expired would
     * walk every failed attempt the shop ever made, every night.
     */
    public function testAShipmentThatWasNeverIssuedIsNotFound(): void
    {
        $export = $this->export('2026-09-22 09:00:00', ['1Z9991'], null);
        $export->setState(CarrierShipmentExportInterface::STATE_FAILED);
        $export->setIssuedAt(null);
        $this->entityManager->flush();

        self::assertSame([], $this->idsFoundBefore(self::MOMENT));
    }

    /**
     * What lets the purge walk more shipments than fit in memory, and get past one whose file could not be
     * deleted: the caller asks for the next few after the last id it saw.
     */
    public function testTheShipmentsComeBackByAscendingIdFromWhereTheCallerLeftOff(): void
    {
        $first = $this->export('2026-09-22 09:00:00', ['1Z9991'], null);
        $second = $this->export('2026-09-22 09:00:00', ['1Z9992'], null);
        $third = $this->export('2026-09-22 09:00:00', ['1Z9993'], null);

        self::assertSame([$first->getId(), $second->getId()], $this->idsFoundBefore(self::MOMENT, 0, 2));
        self::assertSame([$third->getId()], $this->idsFoundBefore(self::MOMENT, (int) $second->getId(), 2));
        self::assertSame([], $this->idsFoundBefore(self::MOMENT, (int) $third->getId(), 2));
    }

    private static function firstLabel(CarrierShipmentExportInterface $export): CarrierShipmentLabelInterface
    {
        $labels = array_values($export->getLabels()->toArray());
        self::assertArrayHasKey(0, $labels);

        return $labels[0];
    }

    /**
     * By id and not by entity: comparing the entities themselves makes a failure print the whole object graph
     * behind a shipment, which is unreadable and takes long enough to look like a hang.
     *
     * @param positive-int $limit
     *
     * @return list<int|null>
     */
    private function idsFoundBefore(string $moment, int $afterId = 0, int $limit = 100): array
    {
        $found = $this->repository->findWithDocumentsIssuedBefore(new \DateTimeImmutable($moment), $afterId, $limit);

        return array_map(static fn (CarrierShipmentExportInterface $export): ?int => $export->getId(), $found);
    }

    /**
     * @param list<string> $trackingNumbers
     */
    private function export(string $issuedAt, array $trackingNumbers, ?string $customsDocumentPath): CarrierShipmentExportInterface
    {
        $export = new CarrierShipmentExport();
        $export->setShipment($this->createShipment());
        $export->setState(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->setCarrier('ups');
        $export->setEnvironment('sandbox');
        $export->setCarrierReference($trackingNumbers[0] ?? '1Z999');
        $export->setIssuedAt(new \DateTimeImmutable($issuedAt));
        $export->setIssuedBy('warehouse@example.com');

        foreach ($trackingNumbers as $position => $trackingNumber) {
            $label = new CarrierShipmentLabel();
            $label->setPosition($position);
            $label->setPath(sprintf('labels/%s-%d.gif', $trackingNumber, $position));
            $label->setFormat('GIF');
            $label->setTrackingNumber($trackingNumber);
            $export->addLabel($label);
        }

        if (null !== $customsDocumentPath) {
            $export->setCustomsDocumentPath($customsDocumentPath);
            $export->setCustomsDocumentFormat('PDF');
        }

        $this->entityManager->persist($export);
        $this->entityManager->flush();

        return $export;
    }

    private function createShipment(): ShipmentInterface
    {
        $zone = new Zone();
        $zone->setCode('purge-zone-' . bin2hex(random_bytes(4)));
        $zone->setName('Purge zone');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);

        $method = new ShippingMethod();
        $method->setCode('purge-method-' . bin2hex(random_bytes(4)));
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
