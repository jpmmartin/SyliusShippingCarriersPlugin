<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Console;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use League\Flysystem\FilesystemOperator;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command a shop puts in its cron. What it has to get right is that it is the shop's own decision: nothing
 * is deleted until somebody runs this, and running it twice is not a problem.
 */
final class PurgeCarrierDocumentsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private FilesystemOperator $storage;

    /** @var list<string> Written by the test into the real storage, so they are taken back out of it. */
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

    public function testItDeletesTheFilesOfAShipmentIssuedLongerAgoThanTheRetention(): void
    {
        $export = $this->export('2023-01-15 09:00:00');
        $tester = $this->createTester();

        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('have been deleted', $tester->getDisplay());
        self::assertFalse($this->storage->fileExists((string) self::firstLabel($export)->getPath()));
        self::assertFalse($this->storage->fileExists((string) $export->getCustomsDocumentPath()));

        $this->entityManager->refresh($export);
        self::assertNotNull(self::firstLabel($export)->getPurgedAt());
        self::assertNotNull($export->getCustomsDocumentPurgedAt());
        // The shipment is still on record, which is the point of purging the file and not the row.
        self::assertSame('warehouse@example.com', $export->getIssuedBy());
    }

    /**
     * Nothing goes because it got old: it goes because the shop asked for it to go. A shipment issued today is
     * still there after the command has run.
     */
    public function testItLeavesAShipmentStillInsideTheRetentionAlone(): void
    {
        $export = $this->export('now');
        $tester = $this->createTester();

        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('nothing kept for longer than allowed', $tester->getDisplay());
        self::assertTrue($this->storage->fileExists((string) self::firstLabel($export)->getPath()));
        self::assertNull(self::firstLabel($export)->getPurgedAt());
    }

    /**
     * A cron runs it every night, so the run after the one that deleted everything has to be a success with
     * nothing to say, not a failure.
     */
    public function testRunningItAgainFindsNothingLeftToDelete(): void
    {
        $this->export('2023-01-15 09:00:00');
        $tester = $this->createTester();
        $tester->execute([], ['interactive' => false]);

        $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('nothing kept for longer than allowed', $tester->getDisplay());
    }

    private static function firstLabel(CarrierShipmentExportInterface $export): CarrierShipmentLabelInterface
    {
        $labels = array_values($export->getLabels()->toArray());
        self::assertArrayHasKey(0, $labels);

        return $labels[0];
    }

    private function createTester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('jpmmartin:carrier:purge-documents'));
    }

    private function export(string $issuedAt): CarrierShipmentExportInterface
    {
        $reference = '1Z' . bin2hex(random_bytes(4));

        $export = new CarrierShipmentExport();
        $export->setShipment($this->createShipment());
        $export->setState(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->setCarrier('ups');
        $export->setEnvironment('sandbox');
        $export->setCarrierReference($reference);
        $export->setIssuedAt(new \DateTimeImmutable($issuedAt));
        $export->setIssuedBy('warehouse@example.com');

        $label = new CarrierShipmentLabel();
        $label->setPosition(0);
        $label->setPath($this->write(sprintf('labels/purge-test/%s-0.gif', $reference), 'the label'));
        $label->setFormat('GIF');
        $label->setTrackingNumber($reference);
        $export->addLabel($label);

        $export->setCustomsDocumentPath($this->write(sprintf('labels/purge-test/customs-%s.pdf', $reference), 'the invoice'));
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
        $zone->setCode('purge-cmd-zone-' . bin2hex(random_bytes(4)));
        $zone->setName('Purge zone');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);

        $method = new ShippingMethod();
        $method->setCode('purge-cmd-method-' . bin2hex(random_bytes(4)));
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
