<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use League\Flysystem\FilesystemOperator;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the admin offers for a shipment that already has its labels: the labels themselves, and cancelling
 * them. And what it stops offering once they are cancelled, because a label the carrier no longer recognises
 * would put a parcel on a van under a number that does not exist.
 */
final class VoidCarrierLabelsAdminTest extends WebTestCase
{
    private const ISSUE_BUTTON = 'data-test-jpmmartin-carrier-issue-labels-button';

    private const VOID_BUTTON = 'data-test-jpmmartin-carrier-void-labels-button';

    private const DOWNLOAD_LINK = 'data-test-jpmmartin-carrier-label-download-link';

    private const VOID_WINDOW = 'data-test-jpmmartin-carrier-void-window';

    private const VOID_TOO_LATE = 'data-test-jpmmartin-carrier-void-too-late';

    private const VOID_REFUSED = 'data-test-jpmmartin-carrier-void-refused';

    private const LABEL_PATH = 'labels/void-test/1Z999AA10123456784-0.gif';

    private const LABEL_CONTENTS = 'GIF89a a UPS label';

    private const CUSTOMS_LINK = 'data-test-jpmmartin-carrier-customs-document-download-link';

    private const CUSTOMS_PATH = 'labels/void-test/customs-1Z999AA10123456784.pdf';

    private const CUSTOMS_CONTENTS = '%PDF-1.4 a commercial invoice';

    use AdminFixturesTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private ChannelInterface $channel;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->entityManager->beginTransaction();

        $this->channel = $this->createChannel('VOID_WEB');
        $this->storage()->write(self::LABEL_PATH, self::LABEL_CONTENTS);
        $this->storage()->write(self::CUSTOMS_PATH, self::CUSTOMS_CONTENTS);
    }

    protected function tearDown(): void
    {
        foreach ([self::LABEL_PATH, self::CUSTOMS_PATH] as $path) {
            if ($this->storage()->fileExists($path)) {
                $this->storage()->delete($path);
            }
        }

        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testAShipmentWithItsLabelsIsOfferedThemAndCancelling(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED);
        $this->client->loginUser($this->createAdmin('void-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(self::VOID_BUTTON, $content);
        self::assertStringContainsString(self::DOWNLOAD_LINK, $content);
        self::assertStringNotContainsString(self::ISSUE_BUTTON, $content, 'A shipment that has its labels is not offered them again.');
    }

    /**
     * The one that matters: a cancelled label is neither downloadable nor cancellable again.
     */
    public function testACancelledShipmentIsOfferedNeitherItsLabelsNorCancellingAgain(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_VOIDED);
        $this->client->loginUser($this->createAdmin('voided-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::VOID_BUTTON, $content);
        self::assertStringNotContainsString(self::DOWNLOAD_LINK, $content);
        self::assertStringContainsString(self::ISSUE_BUTTON, $content, 'Once cancelled there is nothing to overwrite, so it may be issued again.');
    }

    /**
     * A carrier that refused to cancel leaves the labels issued and still being billed, so the reason stays on
     * the screen and not only in the message that flashed by when it was tried.
     */
    public function testTheReasonACarrierGaveForNotCancellingStaysOnTheShipment(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $label = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED);
        $export = $label->getExport();
        self::assertInstanceOf(CarrierShipmentExportInterface::class, $export);
        $export->setFailureReason('UPS did not void the shipment: it is past the void window.');
        $this->entityManager->flush();
        $this->client->loginUser($this->createAdmin('void-refused-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(self::VOID_REFUSED, $content);
        self::assertStringContainsString('it is past the void window', $content);
        self::assertStringContainsString(self::VOID_BUTTON, $content, 'It is still issued, so it may still be tried again.');
    }

    public function testAShipmentThatWasCancelledShowsNoRefusal(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_VOIDED);
        $this->client->loginUser($this->createAdmin('no-refusal-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::VOID_REFUSED, (string) $this->client->getResponse()->getContent());
    }

    /**
     * Ninety days in UPS against twelve hours in FedEx. The screen has to say which one this shipment is on.
     */
    public function testAShipmentIssuedTodayShowsHowLongIsLeftToCancelIt(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $label = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED);
        $this->issuedAt($label, new \DateTimeImmutable('-1 day'));
        $this->client->loginUser($this->createAdmin('void-window-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(self::VOID_WINDOW, $content);
        self::assertStringNotContainsString(self::VOID_TOO_LATE, $content);
    }

    public function testAShipmentPastItsWindowSaysItIsTooLate(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $label = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED);
        $this->issuedAt($label, new \DateTimeImmutable('-100 days'));
        $this->client->loginUser($this->createAdmin('void-window-closed-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(self::VOID_TOO_LATE, $content);
        self::assertStringNotContainsString(self::VOID_WINDOW, $content);
    }

    private function issuedAt(CarrierShipmentLabel $label, \DateTimeImmutable $issuedAt): void
    {
        $export = $label->getExport();
        self::assertInstanceOf(CarrierShipmentExportInterface::class, $export);

        $export->setIssuedAt($issuedAt);
        $this->entityManager->flush();
    }

    public function testAnIssuedLabelIsDownloadedByAnAuthorisedAdministrator(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $label = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED);
        $this->client->loginUser($this->createAdmin('download-label-admin'), 'admin');

        $this->client->request('GET', '/admin/carrier-labels/' . $label->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(self::LABEL_CONTENTS, $this->client->getResponse()->getContent());
    }

    public function testTheLabelOfACancelledShipmentIsNotServed(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $label = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_VOIDED);
        $this->client->loginUser($this->createAdmin('cancelled-label-admin'), 'admin');

        $this->client->request('GET', '/admin/carrier-labels/' . $label->getId());

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::LABEL_CONTENTS, (string) $this->client->getResponse()->getContent());
    }

    public function testWithoutAnAdminSessionNoLabelIsServed(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $label = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED);

        $this->client->request('GET', '/admin/carrier-labels/' . $label->getId());

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertStringNotContainsString(self::LABEL_CONTENTS, (string) $this->client->getResponse()->getContent());
    }

    /**
     * The customs document is handed over under the rules of a label: offered with them, served to an
     * authorised administrator only, and no longer once the shipment is cancelled.
     */
    public function testTheCustomsDocumentIsOfferedWithTheLabelsAndDownloadedByAnAuthorisedAdministrator(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $export = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED, true)->getExport();
        self::assertInstanceOf(CarrierShipmentExportInterface::class, $export);
        $this->client->loginUser($this->createAdmin('customs-document-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());
        self::assertStringContainsString(self::CUSTOMS_LINK, (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/carrier-customs-documents/' . $export->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(self::CUSTOMS_CONTENTS, $this->client->getResponse()->getContent());
    }

    public function testWithoutAnAdminSessionNoCustomsDocumentIsServed(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $export = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED, true)->getExport();
        self::assertInstanceOf(CarrierShipmentExportInterface::class, $export);

        $this->client->request('GET', '/admin/carrier-customs-documents/' . $export->getId());

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertStringNotContainsString(self::CUSTOMS_CONTENTS, (string) $this->client->getResponse()->getContent());
    }

    public function testTheCustomsDocumentOfACancelledShipmentIsNeitherOfferedNorServed(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $export = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_VOIDED, true)->getExport();
        self::assertInstanceOf(CarrierShipmentExportInterface::class, $export);
        $this->client->loginUser($this->createAdmin('cancelled-customs-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());
        self::assertStringNotContainsString(self::CUSTOMS_LINK, (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/carrier-customs-documents/' . $export->getId());

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::CUSTOMS_CONTENTS, (string) $this->client->getResponse()->getContent());
    }

    public function testAShipmentWithoutACustomsDocumentOffersNone(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED);
        $this->client->loginUser($this->createAdmin('domestic-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::CUSTOMS_LINK, (string) $this->client->getResponse()->getContent());
    }

    /**
     * Once the retention is over the files are gone, but the shipment is not: the screen stops offering what it
     * can no longer serve and goes on saying the labels were issued, which is what makes the purge safe to run.
     */
    public function testAShipmentWhoseFilesWerePurgedOffersNoDownloadsAndIsStillIssued(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $label = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED, true, true);
        $export = $label->getExport();
        self::assertInstanceOf(CarrierShipmentExportInterface::class, $export);
        $this->client->loginUser($this->createAdmin('purged-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::DOWNLOAD_LINK, $content);
        self::assertStringNotContainsString(self::CUSTOMS_LINK, $content);
        self::assertStringNotContainsString(self::ISSUE_BUTTON, $content, 'The labels were issued and still are: they are not offered again.');
        self::assertStringContainsString(self::VOID_BUTTON, $content);

        // What was sent, by whom and when is still on record.
        self::assertSame('1Z999AA10123456784', $label->getTrackingNumber());
        self::assertSame('warehouse@example.com', $export->getIssuedBy());
        self::assertNotNull($export->getIssuedAt());
    }

    public function testNeitherThePurgedLabelNorThePurgedCustomsDocumentIsServed(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $label = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_ISSUED, true, true);
        $export = $label->getExport();
        self::assertInstanceOf(CarrierShipmentExportInterface::class, $export);
        $this->client->loginUser($this->createAdmin('purged-download-admin'), 'admin');

        $this->client->request('GET', '/admin/carrier-labels/' . $label->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/carrier-customs-documents/' . $export->getId());
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The file of a cancelled label stays in the store until the retention takes it, because it is still
     * evidence of what went out. Being there must not make it reachable: a cancelled label would put a parcel
     * on a van under a number the carrier no longer recognises.
     *
     * Asking for it by its path used to work, over the head of every check the action by id makes. There is no
     * longer a route that takes a path, and this is what says so if one ever comes back.
     */
    public function testTheFileOfACancelledLabelIsStillStoredButCannotBeReached(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $label = $this->export($order->getShipments()->first(), CarrierShipmentExportInterface::STATE_VOIDED, true);
        $this->client->loginUser($this->createAdmin('cancelled-by-path-admin'), 'admin');

        // It is still stored: nothing has deleted it, so the only thing keeping it back is the refusal to serve it.
        self::assertTrue($this->storage()->fileExists(self::LABEL_PATH));

        $this->client->request('GET', '/admin/carrier-documents/' . self::LABEL_PATH);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::LABEL_CONTENTS, (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/carrier-documents/' . self::CUSTOMS_PATH);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::CUSTOMS_CONTENTS, (string) $this->client->getResponse()->getContent());

        // And not by its own id either, which is the check that was being walked around.
        $this->client->request('GET', '/admin/carrier-labels/' . $label->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutTheAdminsOwnTokenNothingIsCancelled(): void
    {
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
        $shipment = $order->getShipments()->first();
        $export = $this->export($shipment, CarrierShipmentExportInterface::STATE_ISSUED)->getExport();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        self::assertInstanceOf(CarrierShipmentExportInterface::class, $export);
        $this->client->loginUser($this->createAdmin('void-no-token-admin'), 'admin');

        $this->client->request('POST', '/admin/carrier-shipments/' . $shipment->getId() . '/void-labels');

        self::assertFalse($this->client->getResponse()->isSuccessful());
        $this->entityManager->refresh($export);
        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $export->getState());
    }

    /**
     * @param CarrierShipmentExportInterface::STATE_* $state
     * @param bool $purged As the retention leaves it: the files deleted, everything that says what was shipped
     *                     still there
     */
    private function export(mixed $shipment, string $state, bool $withCustomsDocument = false, bool $purged = false): CarrierShipmentLabel
    {
        self::assertInstanceOf(ShipmentInterface::class, $shipment);

        $export = new CarrierShipmentExport();
        $export->setShipment($shipment);
        $export->setCarrier('ups');
        $export->setState($state);
        $export->setCarrierReference('1Z999AA10123456784');
        $export->setIssuedAt(new \DateTimeImmutable('2026-03-01 10:00:00'));
        $export->setIssuedBy('warehouse@example.com');
        if ($withCustomsDocument) {
            $export->setCustomsDocumentPath(self::CUSTOMS_PATH);
            $export->setCustomsDocumentFormat('PDF');
            if ($purged) {
                $export->setCustomsDocumentPurgedAt(new \DateTimeImmutable('2026-09-22 10:00:00'));
            }
        }

        $label = new CarrierShipmentLabel();
        $label->setPosition(0);
        $label->setPath(self::LABEL_PATH);
        $label->setFormat('GIF');
        $label->setTrackingNumber('1Z999AA10123456784');
        if ($purged) {
            $label->setPurgedAt(new \DateTimeImmutable('2026-09-22 10:00:00'));
        }
        $export->addLabel($label);

        $this->entityManager->persist($export);
        $this->entityManager->flush();

        return $label;
    }

    private function storage(): FilesystemOperator
    {
        $storage = self::getContainer()->get('jpmmartin_carrier.storage.documents');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }
}
