<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin\DownloadCarrierCustomsDocumentAction;
use JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin\DownloadCarrierDocumentAction;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The customs document goes by the export's record, under the rules of a label: the paperwork of a cancelled
 * shipment declares a parcel the carrier no longer has.
 */
final class DownloadCarrierCustomsDocumentActionTest extends TestCase
{
    private const PATH = 'labels/42/customs-1Z999AA10123456784.pdf';

    private const CONTENTS = '%PDF-1.4 a commercial invoice';

    private ?CarrierShipmentExportInterface $export = null;

    private Filesystem $storage;

    private string $storageDirectory;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir() . '/jpmmartin_carrier_customs_' . bin2hex(random_bytes(6));
        $this->storage = new Filesystem(new LocalFilesystemAdapter($this->storageDirectory));
        $this->storage->write(self::PATH, self::CONTENTS);
        $this->export = $this->export(CarrierShipmentExportInterface::STATE_ISSUED);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDirectory)) {
            exec(sprintf('rm -rf %s', escapeshellarg($this->storageDirectory)));
        }
    }

    public function testTheCustomsDocumentOfAnIssuedShipmentIsHandedOver(): void
    {
        $response = ($this->action())(1);

        self::assertSame(self::CONTENTS, $response->getContent());
    }

    public function testTheCustomsDocumentOfACancelledShipmentIsNotHandedOver(): void
    {
        $this->export = $this->export(CarrierShipmentExportInterface::STATE_VOIDED);

        $this->expectException(NotFoundHttpException::class);

        ($this->action())(1);
    }

    public function testAPurgedCustomsDocumentIsNotHandedOver(): void
    {
        $this->export?->setCustomsDocumentPurgedAt(new \DateTimeImmutable('2026-09-21 10:00:00'));

        $this->expectException(NotFoundHttpException::class);

        ($this->action())(1);
    }

    public function testAShipmentWithoutACustomsDocumentHasNoneToHandOver(): void
    {
        $this->export?->setCustomsDocumentPath(null);

        $this->expectException(NotFoundHttpException::class);

        ($this->action())(1);
    }

    public function testAShipmentThatIsNotThereIsNotFound(): void
    {
        $this->export = null;

        $this->expectException(NotFoundHttpException::class);

        ($this->action())(1);
    }

    private function action(): DownloadCarrierCustomsDocumentAction
    {
        /** @var RepositoryInterface<CarrierShipmentExportInterface>&Stub $exportRepository */
        $exportRepository = $this->createStub(RepositoryInterface::class);
        $exportRepository->method('find')->willReturnCallback(fn (): ?CarrierShipmentExportInterface => $this->export);

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);

        return new DownloadCarrierCustomsDocumentAction(
            $exportRepository,
            new DownloadCarrierDocumentAction($this->storage, $authorizationChecker),
        );
    }

    /**
     * @param CarrierShipmentExportInterface::STATE_* $state
     */
    private function export(string $state): CarrierShipmentExportInterface
    {
        $export = new CarrierShipmentExport();
        $export->setCarrier('ups');
        $export->setState($state);
        $export->setCustomsDocumentPath(self::PATH);
        $export->setCustomsDocumentFormat('PDF');

        return $export;
    }
}
