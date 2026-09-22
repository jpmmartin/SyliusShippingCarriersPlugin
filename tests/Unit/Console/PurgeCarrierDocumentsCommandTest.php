<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Console;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Console\Command\PurgeCarrierDocumentsCommand;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Label\DocumentPurger;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelStorage;
use JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierShipmentExportRepositoryInterface;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What the command says and, above all, what it returns: a cron only ever reads the exit code, so a purge that
 * left files behind has to come back as a failure or nobody will ever find out.
 */
final class PurgeCarrierDocumentsCommandTest extends TestCase
{
    /** @var list<CarrierShipmentExportInterface> */
    private array $exports = [];

    private bool $storageRefusesToDelete = false;

    protected function setUp(): void
    {
        $this->exports = [];
        $this->storageRefusesToDelete = false;
    }

    public function testItSaysSoWhenThereWasNothingKeptForLongerThanAllowed(): void
    {
        $tester = $this->tester();

        $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('nothing kept for longer than allowed', $tester->getDisplay());
    }

    public function testItSaysHowMuchItDeletedAndThatTheRecordIsStillThere(): void
    {
        $this->expired();
        $tester = $this->tester();

        $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 file(s) of 1 shipment(s) have been deleted', $tester->getDisplay());
        self::assertStringContainsString('still recorded', $tester->getDisplay());
    }

    /**
     * The one ending a cron has to notice. Without the failing exit code the shop would keep files it believes
     * it deleted, and nothing would ever say so.
     */
    public function testItFailsWhenAFileCouldNotBeDeleted(): void
    {
        $this->expired();
        $this->storageRefusesToDelete = true;
        $tester = $this->tester();

        $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('1 file(s) could not be deleted', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new PurgeCarrierDocumentsCommand($this->purger()));
    }

    private function purger(): DocumentPurger
    {
        return new DocumentPurger(
            $this->exportRepository(),
            new LabelStorage($this->storage()),
            $this->createStub(ObjectManager::class),
            new MockClock('2026-09-22 10:00:00'),
            new NullLogger(),
            180 * 24 * 60 * 60,
        );
    }

    /**
     * @return CarrierShipmentExportRepositoryInterface<CarrierShipmentExportInterface>&Stub
     */
    private function exportRepository(): CarrierShipmentExportRepositoryInterface
    {
        /** @var CarrierShipmentExportRepositoryInterface<CarrierShipmentExportInterface>&Stub $repository */
        $repository = $this->createStub(CarrierShipmentExportRepositoryInterface::class);
        $repository->method('findWithDocumentsIssuedBefore')->willReturnCallback(
            fn (\DateTimeImmutable $moment, int $afterId): array => 0 === $afterId ? $this->exports : [],
        );

        return $repository;
    }

    private function storage(): FilesystemOperator
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('delete')->willReturnCallback(function (string $path): void {
            if ($this->storageRefusesToDelete) {
                throw UnableToDeleteFile::atLocation($path, 'the mount is read-only');
            }
        });

        return $storage;
    }

    private function expired(): void
    {
        $export = new CarrierShipmentExport();
        (new \ReflectionProperty(CarrierShipmentExport::class, 'id'))->setValue($export, 1);
        $export->setState(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->setCarrier('ups');
        $export->setCarrierReference('1Z9991');
        $export->setIssuedAt(new \DateTimeImmutable('2024-01-01 10:00:00'));
        $export->setIssuedBy('warehouse@example.com');

        $label = new CarrierShipmentLabel();
        $label->setPosition(0);
        $label->setPath('labels/1/1Z9991-0.gif');
        $label->setFormat('GIF');
        $label->setTrackingNumber('1Z9991');
        $export->addLabel($label);

        $this->exports[] = $export;
    }
}
