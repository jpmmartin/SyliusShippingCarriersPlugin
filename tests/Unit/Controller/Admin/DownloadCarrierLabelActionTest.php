<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin\DownloadCarrierDocumentAction;
use JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin\DownloadCarrierLabelAction;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * A label is downloaded by its own record and not by a path, because what may be handed over depends on what
 * happened to the shipment: a label the carrier cancelled would put a parcel on a van under a number that no
 * longer exists.
 */
final class DownloadCarrierLabelActionTest extends TestCase
{
    private const PATH = 'labels/42/1Z999AA10123456784-0.gif';

    private const CONTENTS = 'GIF89a a UPS label';

    private ?CarrierShipmentLabelInterface $label = null;

    private Filesystem $storage;

    private string $storageDirectory;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir() . '/jpmmartin_carrier_label_' . bin2hex(random_bytes(6));
        $this->storage = new Filesystem(new LocalFilesystemAdapter($this->storageDirectory));
        $this->storage->write(self::PATH, self::CONTENTS);
        $this->label = $this->label(CarrierShipmentExportInterface::STATE_ISSUED);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDirectory)) {
            exec(sprintf('rm -rf %s', escapeshellarg($this->storageDirectory)));
        }
    }

    public function testAnIssuedLabelIsHandedOver(): void
    {
        $response = ($this->action())(1);

        self::assertSame(self::CONTENTS, $response->getContent());
    }

    /**
     * The one that matters: what the carrier no longer recognises is not a label any more.
     */
    public function testACancelledLabelIsNotHandedOver(): void
    {
        $this->label = $this->label(CarrierShipmentExportInterface::STATE_VOIDED);

        $this->expectException(NotFoundHttpException::class);

        ($this->action())(1);
    }

    public function testALabelWhoseFileWasPurgedIsNotHandedOver(): void
    {
        $this->label?->setPurgedAt(new \DateTimeImmutable('2026-09-21 10:00:00'));

        $this->expectException(NotFoundHttpException::class);

        ($this->action())(1);
    }

    public function testALabelThatIsNotThereIsNotFound(): void
    {
        $this->label = null;

        $this->expectException(NotFoundHttpException::class);

        ($this->action())(1);
    }

    private function action(): DownloadCarrierLabelAction
    {
        /** @var RepositoryInterface<CarrierShipmentLabelInterface>&Stub $labelRepository */
        $labelRepository = $this->createStub(RepositoryInterface::class);
        $labelRepository->method('find')->willReturnCallback(fn (): ?CarrierShipmentLabelInterface => $this->label);

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);

        return new DownloadCarrierLabelAction(
            $labelRepository,
            new DownloadCarrierDocumentAction($this->storage, $authorizationChecker),
        );
    }

    /**
     * @param CarrierShipmentExportInterface::STATE_* $state
     */
    private function label(string $state): CarrierShipmentLabelInterface
    {
        $export = new CarrierShipmentExport();
        $export->setCarrier('ups');
        $export->setState($state);

        $label = new CarrierShipmentLabel();
        $label->setPosition(0);
        $label->setPath(self::PATH);
        $label->setFormat('GIF');
        $label->setTrackingNumber('1Z999AA10123456784');
        $export->addLabel($label);

        return $label;
    }
}
