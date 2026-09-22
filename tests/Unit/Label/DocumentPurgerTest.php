<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Label;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\DocumentPurger;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelStorage;
use JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierShipmentExportRepositoryInterface;
use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

/**
 * The purge is the only thing in the plugin that destroys anything, so what it must not do matters as much as
 * what it does: nothing inside the retention is touched, and what it deletes leaves behind the record of what
 * was shipped.
 */
final class DocumentPurgerTest extends TestCase
{
    /** The longest a shipment can be cancelled for, which is what the retention defaults to. */
    private const RETENTION = 180 * 24 * 60 * 60;

    /** Far longer than issuing takes, which is what tells an abandoned file from one being written. */
    private const TEMPORARY_RETENTION = 24 * 60 * 60;

    private const NOW = '2026-09-22 10:00:00';

    /** Issued this long before now, a file is one second past the retention. */
    private const EXPIRED = '2026-03-26 09:59:59';

    /** Issued this long before now, a file has one second of retention left. */
    private const STILL_KEPT = '2026-03-26 10:00:01';

    private RecordingLogger $logger;

    private FilesystemOperator $storage;

    private string $storageDirectory;

    /** @var list<CarrierShipmentExportInterface> What the repository holds, in the order it gives them back. */
    private array $exports = [];

    /** @var list<string> Paths the storage refuses to delete, as a shop with a broken mount would. */
    private array $unwritable = [];

    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->storageDirectory = sys_get_temp_dir() . '/jpmmartin_carrier_purge_' . bin2hex(random_bytes(6));
        $this->storage = new Filesystem(new LocalFilesystemAdapter($this->storageDirectory));
        $this->exports = [];
        $this->unwritable = [];
        $this->flushes = 0;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDirectory)) {
            exec(sprintf('rm -rf %s', escapeshellarg($this->storageDirectory)));
        }
    }

    public function testTheFilesKeptForLongerThanAllowedAreGoneAndTheirRecordSaysWhen(): void
    {
        $export = $this->export(1, self::EXPIRED, ['labels/1/1Z9991-0.gif', 'labels/1/1Z9992-1.gif'], 'labels/1/customs-1Z9991.pdf');

        $report = $this->purger()->purge();

        self::assertFalse($this->storage->fileExists('labels/1/1Z9991-0.gif'));
        self::assertFalse($this->storage->fileExists('labels/1/1Z9992-1.gif'));
        self::assertFalse($this->storage->fileExists('labels/1/customs-1Z9991.pdf'));

        foreach ($export->getLabels() as $label) {
            self::assertEquals(new \DateTimeImmutable(self::NOW), $label->getPurgedAt());
        }
        self::assertEquals(new \DateTimeImmutable(self::NOW), $export->getCustomsDocumentPurgedAt());

        self::assertSame(3, $report->deletedFiles);
        self::assertSame(0, $report->failedFiles);
        self::assertSame(1, $report->shipments);
    }

    /**
     * Deleting the file is not deleting the shipment: what went out, who sent it and when has to survive the
     * purge, or the purge costs the shop its own record of what it did.
     */
    public function testWhatWasShippedIsStillOnRecordAfterThePurge(): void
    {
        $export = $this->export(1, self::EXPIRED, ['labels/1/1Z9991-0.gif'], 'labels/1/customs-1Z9991.pdf');

        $this->purger()->purge();

        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $export->getState());
        self::assertSame('ups', $export->getCarrier());
        self::assertSame('1Z9991', $export->getCarrierReference());
        self::assertSame('warehouse@example.com', $export->getIssuedBy());
        self::assertEquals(new \DateTimeImmutable(self::EXPIRED), $export->getIssuedAt());

        $label = self::firstLabel($export);
        self::assertSame('1Z9991', $label->getTrackingNumber());
        self::assertSame('labels/1/1Z9991-0.gif', $label->getPath());
        self::assertSame('labels/1/customs-1Z9991.pdf', $export->getCustomsDocumentPath());
    }

    public function testNothingStillInsideTheRetentionIsTouched(): void
    {
        $export = $this->export(1, self::STILL_KEPT, ['labels/1/1Z9991-0.gif'], 'labels/1/customs-1Z9991.pdf');

        $report = $this->purger()->purge();

        self::assertTrue($this->storage->fileExists('labels/1/1Z9991-0.gif'));
        self::assertTrue($this->storage->fileExists('labels/1/customs-1Z9991.pdf'));
        self::assertNull(self::firstLabel($export)->getPurgedAt());
        self::assertNull($export->getCustomsDocumentPurgedAt());
        self::assertSame(0, $report->deletedFiles);
        self::assertSame(0, $report->shipments);
    }

    /**
     * The boundary is the reason the retention is a promise and not an approximation: a shipment is kept for
     * the whole window and goes the moment it is over, never the other way round.
     */
    public function testTheLastSecondOfTheRetentionStillKeepsTheFile(): void
    {
        $this->export(1, '2026-03-26 10:00:00', ['labels/1/1Z9991-0.gif'], null);

        $report = $this->purger()->purge();

        self::assertTrue($this->storage->fileExists('labels/1/1Z9991-0.gif'));
        self::assertSame(0, $report->deletedFiles);
    }

    public function testALabelPurgedBeforeKeepsTheMomentItWasPurged(): void
    {
        $export = $this->export(1, self::EXPIRED, ['labels/1/1Z9991-0.gif', 'labels/1/1Z9992-1.gif'], null);
        $labels = array_values($export->getLabels()->toArray());
        $labels[0]->setPurgedAt(new \DateTimeImmutable('2026-09-01 08:00:00'));
        $this->storage->delete('labels/1/1Z9991-0.gif');

        $report = $this->purger()->purge();

        self::assertEquals(new \DateTimeImmutable('2026-09-01 08:00:00'), $labels[0]->getPurgedAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $labels[1]->getPurgedAt());
        self::assertSame(1, $report->deletedFiles);
    }

    /**
     * A record that says the file is gone while it is still stored would leave it there forever, with nothing
     * left to point at it. So a file the storage will not delete keeps its record untouched and is tried again.
     */
    public function testAFileTheStorageWillNotDeleteKeepsItsRecordAndIsReported(): void
    {
        $export = $this->export(1, self::EXPIRED, ['labels/1/1Z9991-0.gif', 'labels/1/1Z9992-1.gif'], null);
        $this->unwritable = ['labels/1/1Z9991-0.gif'];

        $report = $this->purger()->purge();

        $labels = array_values($export->getLabels()->toArray());
        self::assertNull($labels[0]->getPurgedAt());
        self::assertTrue($this->storage->fileExists('labels/1/1Z9991-0.gif'));
        self::assertEquals(new \DateTimeImmutable(self::NOW), $labels[1]->getPurgedAt());

        self::assertSame(1, $report->deletedFiles);
        self::assertSame(1, $report->failedFiles);
        self::assertSame(1, $report->shipments);
        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0][0]);
        self::assertSame('labels/1/1Z9991-0.gif', $this->logger->records[0][2]['path'] ?? null);
    }

    public function testAShipmentWhoseOnlyFileCouldNotBeDeletedIsNotCountedAsPurged(): void
    {
        $this->export(1, self::EXPIRED, ['labels/1/1Z9991-0.gif'], null);
        $this->unwritable = ['labels/1/1Z9991-0.gif'];

        $report = $this->purger()->purge();

        self::assertSame(0, $report->deletedFiles);
        self::assertSame(1, $report->failedFiles);
        self::assertSame(0, $report->shipments);
    }

    /**
     * A shop that has been shipping for years has more expired files than fit in memory, so they are read in
     * batches. The test would pass either way if the walk stopped after the first batch, which is what it is
     * here to catch.
     */
    public function testEveryExpiredShipmentIsReachedHoweverManyThereAre(): void
    {
        for ($id = 1; $id <= 250; ++$id) {
            $this->export($id, self::EXPIRED, [sprintf('labels/%d/1Z999-0.gif', $id)], null);
        }

        $report = $this->purger()->purge();

        self::assertSame(250, $report->deletedFiles);
        self::assertSame(250, $report->shipments);
        self::assertSame(3, $this->flushes);
    }

    /**
     * Nothing to purge means nothing written: a cron that runs every night must not touch the database 364
     * times for the one night it has something to do.
     */
    public function testAPurgeWithNothingToDeleteWritesNothing(): void
    {
        $this->export(1, self::STILL_KEPT, ['labels/1/1Z9991-0.gif'], null);

        $report = $this->purger()->purge();

        self::assertSame(0, $this->flushes);
        self::assertSame(0, $report->deletedFiles);
    }

    /**
     * Nothing names a waiting file, so if the purge does not take it nothing ever will: it stays in the store
     * for good, with an address inside and no way to reach it.
     */
    public function testAFileLeftWaitingByAnIssueThatNeverFinishedIsCollected(): void
    {
        $this->waiting('labels/pending/abandoned.gif', '2026-09-21 09:00:00');

        $report = $this->purger()->purge();

        self::assertFalse($this->storage->fileExists('labels/pending/abandoned.gif'));
        self::assertSame(1, $report->temporaryFiles);
        // It was nobody's label, so no shipment lost a file.
        self::assertSame(0, $report->deletedFiles);
        self::assertSame(0, $report->shipments);
    }

    /**
     * An issue being written right now has its file in there too, and taking it would break the issue it
     * belongs to.
     */
    public function testAFileWrittenJustNowIsLeftWaiting(): void
    {
        $this->waiting('labels/pending/in-flight.gif', '2026-09-22 09:59:00');

        $report = $this->purger()->purge();

        self::assertTrue($this->storage->fileExists('labels/pending/in-flight.gif'));
        self::assertSame(0, $report->temporaryFiles);
    }

    public function testAWaitingFileTheStorageWillNotDeleteIsReportedLikeAnyOther(): void
    {
        $this->waiting('labels/pending/abandoned.gif', '2026-09-21 09:00:00');
        $this->unwritable = ['labels/pending/abandoned.gif'];

        $report = $this->purger()->purge();

        self::assertSame(0, $report->temporaryFiles);
        self::assertSame(1, $report->failedFiles);
    }

    private function purger(): DocumentPurger
    {
        return new DocumentPurger(
            $this->exportRepository(),
            new LabelStorage($this->storage()),
            $this->manager(),
            new MockClock(self::NOW),
            $this->logger,
            self::RETENTION,
            self::TEMPORARY_RETENTION,
        );
    }

    /**
     * Answers what the query answers: issued before the moment, still keeping a file, by ascending id from
     * where the caller left off.
     *
     * @return CarrierShipmentExportRepositoryInterface<CarrierShipmentExportInterface>&Stub
     */
    private function exportRepository(): CarrierShipmentExportRepositoryInterface
    {
        /** @var CarrierShipmentExportRepositoryInterface<CarrierShipmentExportInterface>&Stub $repository */
        $repository = $this->createStub(CarrierShipmentExportRepositoryInterface::class);
        $repository->method('findWithDocumentsIssuedBefore')->willReturnCallback(
            function (\DateTimeImmutable $moment, int $afterId, int $limit): array {
                $found = [];

                foreach ($this->exports as $export) {
                    if ((int) $export->getId() <= $afterId || $export->getIssuedAt() >= $moment || !self::keepsAFile($export)) {
                        continue;
                    }

                    $found[] = $export;

                    if (\count($found) === $limit) {
                        break;
                    }
                }

                return $found;
            },
        );

        return $repository;
    }

    private static function keepsAFile(CarrierShipmentExportInterface $export): bool
    {
        if (null !== $export->getCustomsDocumentPath() && null === $export->getCustomsDocumentPurgedAt()) {
            return true;
        }

        foreach ($export->getLabels() as $label) {
            if (null !== $label->getPath() && !$label->isPurged()) {
                return true;
            }
        }

        return false;
    }

    private function manager(): ObjectManager
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return $manager;
    }

    /**
     * The storage of a shop whose mount has gone read-only for some files, which is how a failed delete shows
     * up: the file is still there and the plugin is told so.
     */
    private function storage(): FilesystemOperator
    {
        if ([] === $this->unwritable) {
            return $this->storage;
        }

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('delete')->willReturnCallback(function (string $path): void {
            if (\in_array($path, $this->unwritable, true)) {
                throw UnableToDeleteFile::atLocation($path, 'the mount is read-only');
            }

            $this->storage->delete($path);
        });
        // Everything but deleting is the real store: only the deletes are what a broken mount refuses.
        $storage->method('listContents')->willReturnCallback(
            fn (string $location, bool $deep = false): DirectoryListing => $this->storage->listContents($location, $deep),
        );

        return $storage;
    }

    private function waiting(string $path, string $writtenAt): void
    {
        $this->storage->write($path, 'a label nobody was told about');
        touch($this->storageDirectory . '/' . $path, (int) strtotime($writtenAt));
    }

    /**
     * @param list<string> $labelPaths
     */
    private function export(int $id, string $issuedAt, array $labelPaths, ?string $customsDocumentPath): CarrierShipmentExportInterface
    {
        $export = new CarrierShipmentExport();
        self::identify($export, CarrierShipmentExport::class, $id);
        $export->setState(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->setCarrier('ups');
        $export->setEnvironment('sandbox');
        $export->setCarrierReference('1Z9991');
        $export->setIssuedAt(new \DateTimeImmutable($issuedAt));
        $export->setIssuedBy('warehouse@example.com');

        foreach ($labelPaths as $position => $path) {
            $label = new CarrierShipmentLabel();
            $label->setPosition($position);
            $label->setPath($path);
            $label->setFormat('GIF');
            $label->setTrackingNumber(basename($path, '-' . $position . '.gif'));
            $export->addLabel($label);
            $this->storage->write($path, 'the label');
        }

        if (null !== $customsDocumentPath) {
            $export->setCustomsDocumentPath($customsDocumentPath);
            $export->setCustomsDocumentFormat('PDF');
            $this->storage->write($customsDocumentPath, 'the invoice');
        }

        $this->exports[] = $export;

        return $export;
    }

    private static function firstLabel(CarrierShipmentExportInterface $export): CarrierShipmentLabelInterface
    {
        $labels = array_values($export->getLabels()->toArray());
        self::assertArrayHasKey(0, $labels);

        return $labels[0];
    }

    /**
     * @param class-string $class
     */
    private static function identify(object $entity, string $class, int $id): void
    {
        $property = new \ReflectionProperty($class, 'id');
        $property->setValue($entity, $id);
    }
}
