<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Label;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\AddressFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\IssuedLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelCarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentResult;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\VoidResult;
use JpmMartin\SyliusShippingCarriersPlugin\Customs\CustomsDataProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Customs\DeclaredValueCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationTypeResolverInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsData;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsDataInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackaging;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackagingInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AlreadyIssuedException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AmbiguousShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelIssuer;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelStorage;
use JpmMartin\SyliusShippingCarriersPlugin\Label\ShipmentRequestFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShipmentCarrier;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShippingChargeResolver;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Registry\ServiceRegistry;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Calculator\FlatRateCalculator;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\Factory;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

/**
 * Issuing has three endings and only one of them is the happy one. The two that are not — the carrier refusing
 * and the carrier saying nothing usable — are the ones that decide whether a shipment can be tried again, so
 * each has a test of its own.
 */
final class LabelIssuerTest extends TestCase
{
    private const SHIPMENT_ID = 42;

    private RecordingLogger $logger;

    private FilesystemOperator $storage;

    private string $storageDirectory;

    /** The carrier's answer to ship(), or what it throws instead. */
    private ShipmentResult|\Throwable $answer;

    /** What the carrier says when asked whether it issued what it never answered about. */
    private ShipmentResult|\Throwable|null $recovery = null;

    private ?CarrierShipmentExportInterface $existingExport = null;

    /** @var list<ShipmentRequest> */
    private array $requests = [];

    /** @var list<string> The references the carrier was asked about. */
    private array $recovered = [];

    private ?CarrierShipmentPackagingInterface $packaging = null;

    private ?CarrierCredentialsInterface $credentials = null;

    private ?CarrierShippingOriginInterface $origin = null;

    /** Where the order is going. Only a shipment that leaves its country declares anything. */
    private string $destinationCountry = 'US';

    /** @var array<string, CarrierCustomsDataInterface> The customs data of the catalogue, by variant code */
    private array $customsData = [];

    /** What happens while the rows are being committed, so a test can look at the storage at that instant. */
    private ?\Closure $whileCommitting = null;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->storageDirectory = sys_get_temp_dir() . '/jpmmartin_carrier_labels_' . bin2hex(random_bytes(6));
        $this->storage = new Filesystem(new LocalFilesystemAdapter($this->storageDirectory));
        $this->requests = [];
        $this->recovered = [];
        $this->packaging = $this->storedPackaging();
        $this->credentials = $this->storedCredentials();
        $this->origin = $this->origin();
        $this->whileCommitting = null;
        $this->destinationCountry = 'US';
        $this->customsData = [];
        $this->recovery = null;
        $this->existingExport = null;
        $this->answer = new ShipmentResult('1Z999AA10123456784', [
            new IssuedLabel(0, '1Z999AA10123456784', 'GIF', 'the first label'),
            new IssuedLabel(1, '1Z999AA10123456795', 'GIF', 'the second label'),
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDirectory)) {
            exec(sprintf('rm -rf %s', escapeshellarg($this->storageDirectory)));
        }
    }

    public function testEveryStoredPackageGetsItsLabelKeptInThePrivateStorage(): void
    {
        $shipment = $this->shipment();

        $export = $this->issuer()->issue($shipment, 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $export->getState());
        self::assertSame('ups', $export->getCarrier());
        self::assertSame('sandbox', $export->getEnvironment());
        self::assertSame('1Z999AA10123456784', $export->getCarrierReference());
        self::assertSame('warehouse@example.com', $export->getIssuedBy());
        self::assertEquals(new \DateTimeImmutable('2026-09-21 10:00:00'), $export->getIssuedAt());
        self::assertNull($export->getFailureReason());

        self::assertCount(2, $export->getLabels());
        $labels = array_values($export->getLabels()->toArray());
        self::assertSame([0, 1], array_map(static fn (CarrierShipmentLabelInterface $label): int => $label->getPosition(), $labels));
        self::assertSame(
            ['1Z999AA10123456784', '1Z999AA10123456795'],
            array_map(static fn (CarrierShipmentLabelInterface $label): ?string => $label->getTrackingNumber(), $labels),
        );

        self::assertSame('labels/42/1Z999AA10123456784-0.gif', $labels[0]->getPath());
        self::assertSame('the first label', $this->storage->read((string) $labels[0]->getPath()));
        self::assertSame('labels/42/1Z999AA10123456795-1.gif', $labels[1]->getPath());
        self::assertSame('the second label', $this->storage->read((string) $labels[1]->getPath()));
    }

    /**
     * What the buyer paid for and what goes on the van have to be the same parcels: the catalogue and the boxes
     * may well have changed since the order was confirmed.
     */
    public function testThePackagesSentAreTheStoredOnesAndNothingIsPackedAgain(): void
    {
        $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('03', $request->serviceCode);
        self::assertSame('GIF', $request->labelFormat);
        self::assertCount(2, $request->packages);
        self::assertSame(
            ['Medium', 13.5, 11.25, 9.75, 'in', 5.5, 'lb'],
            [
                $request->packages[0]->package->boxName,
                $request->packages[0]->package->length,
                $request->packages[0]->package->width,
                $request->packages[0]->package->height,
                $request->packages[0]->package->dimensionUnit,
                $request->packages[0]->package->weight,
                $request->packages[0]->package->weightUnit,
            ],
        );
        self::assertSame([null, 20.0], [$request->packages[1]->package->boxName, $request->packages[1]->package->length]);
    }

    public function testTheShipmentKeepsTheNumberTheCarrierGaveWithoutAnybodyTypingIt(): void
    {
        $shipment = $this->shipment();
        self::assertNull($shipment->getTracking());

        $this->issuer()->issue($shipment, 'warehouse@example.com');

        self::assertSame('1Z999AA10123456784', $shipment->getTracking());
    }

    public function testACarrierThatRefusesLeavesTheShipmentFailedAndNothingStored(): void
    {
        $this->answer = new CarrierRejectedRequestException('UPS rejected the shipment request: the postcode is not served.');
        $shipment = $this->shipment();

        $export = $this->issuer()->issue($shipment, 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_FAILED, $export->getState());
        self::assertSame('UPS rejected the shipment request: the postcode is not served.', $export->getFailureReason());
        self::assertCount(0, $export->getLabels());
        self::assertNull($shipment->getTracking());
        self::assertSame([], $this->storedFiles());
        self::assertSame(LogLevel::ERROR, $this->logger->records[0][0] ?? null);
    }

    /**
     * The one ending that can be paid for twice: nobody knows whether the carrier issued the labels, so it is
     * neither issued nor failed and nothing may retry it on its own.
     */
    public function testACarrierThatGivesNoUsableAnswerLeavesTheShipmentNeedingACheck(): void
    {
        $this->answer = new CarrierUnavailableException('UPS could not be reached: the request timed out.');
        $shipment = $this->shipment();

        $export = $this->issuer()->issue($shipment, 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_NEEDS_CHECK, $export->getState());
        self::assertSame('UPS could not be reached: the request timed out.', $export->getFailureReason());
        self::assertCount(0, $export->getLabels());
        self::assertNull($shipment->getTracking());
        self::assertSame([], $this->storedFiles());
    }

    public function testAnAnswerTheCarrierCannotBeUnderstoodByAlsoNeedsAChecking(): void
    {
        $this->answer = new UnexpectedCarrierResponseException('UPS answered with something the plugin cannot read.');

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_NEEDS_CHECK, $export->getState());
    }

    public function testAShipmentWhosePackagesWereNeverStoredIsNotEvenHandedToTheCarrier(): void
    {
        $this->packaging = null;

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_FAILED, $export->getState());
        self::assertStringContainsString('never stored', (string) $export->getFailureReason());
        self::assertSame([], $this->requests);
    }

    public function testAShipmentWhosePackagingFailedIsNotIssuedAndSaysWhy(): void
    {
        $packaging = new CarrierShipmentPackaging();
        $packaging->fail('The variant "MUG" has no weight declared.');
        $this->packaging = $packaging;

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_FAILED, $export->getState());
        self::assertStringContainsString('The variant "MUG" has no weight declared.', (string) $export->getFailureReason());
        self::assertSame([], $this->requests);
    }

    public function testWithoutStoredCredentialsNothingIsAskedOfTheCarrier(): void
    {
        $this->credentials = null;

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_FAILED, $export->getState());
        self::assertSame([], $this->requests);
    }

    /**
     * A label is only where its row says it is once the row exists. Until then it waits where nothing serves it
     * from, so a failure in between leaves neither a row without its file nor a file without its row.
     */
    public function testALabelIsOnlyPutInPlaceOnceItsRowHasBeenCommitted(): void
    {
        $seen = [];
        $this->whileCommitting = function () use (&$seen): void {
            $seen = $this->storedFiles();
        };

        $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertCount(2, $seen, 'Both labels wait where nothing serves them from until the rows exist.');
        foreach ($seen as $path) {
            self::assertStringStartsWith(LabelStorage::PENDING_DIRECTORY . '/', $path);
        }

        self::assertSame(
            ['labels/42/1Z999AA10123456784-0.gif', 'labels/42/1Z999AA10123456795-1.gif'],
            $this->storedFiles(),
        );
    }

    /**
     * The proof of the criterion that forbids half a state: neither the row nor the file may survive alone.
     */
    public function testAFailureWhileTheRowsAreCommittedLeavesNeitherRowNorFile(): void
    {
        $this->whileCommitting = static fn (): never => throw new \RuntimeException('The database went away.');
        $shipment = $this->shipment();

        try {
            $this->issuer()->issue($shipment, 'warehouse@example.com');
            self::fail('A failure while the rows are committed has to reach the caller.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The database went away.', $exception->getMessage());
        }

        self::assertSame([], $this->storedFiles(), 'No label may be left anywhere.');
    }

    public function testTheCarrierIsGivenANameOfThePluginsOwnThatIsKeptOnTheExport(): void
    {
        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertNotSame('', (string) $export->getOwnReference());
        self::assertSame($export->getOwnReference(), $this->requests[0]->ownReference);
    }

    /**
     * A second attempt must not be able to be mistaken for the first when the carrier is asked about it.
     */
    public function testEachAttemptIsGivenANameOfItsOwn(): void
    {
        $this->answer = new CarrierRejectedRequestException('UPS rejected the shipment request.');

        $first = $this->issuer()->issue($this->shipment(), 'warehouse@example.com')->getOwnReference();
        $second = $this->issuer()->issue($this->shipment(), 'warehouse@example.com')->getOwnReference();

        self::assertNotSame($first, $second);
    }

    /**
     * UPS can be asked whether it issued what it never answered about, so the ambiguity resolves itself.
     */
    public function testACarrierThatCanSayItDidIssueResolvesTheAmbiguityByItself(): void
    {
        $this->answer = new CarrierUnavailableException('UPS could not be reached: the request timed out.');
        $this->recovery = new ShipmentResult('1Z999AA10123456784', [
            new IssuedLabel(0, '1Z999AA10123456784', 'GIF', 'the first label'),
            new IssuedLabel(1, '1Z999AA10123456795', 'GIF', 'the second label'),
        ]);
        $shipment = $this->shipment();

        $export = $this->issuer()->issue($shipment, 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $export->getState());
        self::assertSame([$export->getOwnReference()], $this->recovered, 'It is asked about by the name the plugin gave it.');
        self::assertCount(2, $export->getLabels());
        self::assertSame('1Z999AA10123456784', $shipment->getTracking());
    }

    public function testACarrierThatCannotBeAskedLeavesTheAmbiguityForAPerson(): void
    {
        $this->answer = new CarrierUnavailableException('FedEx could not be reached: the request timed out.');
        $this->recovery = null;

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_NEEDS_CHECK, $export->getState());
        self::assertCount(1, $this->recovered);
    }

    public function testAskingWhetherItWasIssuedFailingLeavesTheAmbiguityForAPerson(): void
    {
        $this->answer = new CarrierUnavailableException('UPS could not be reached: the request timed out.');
        $this->recovery = new CarrierUnavailableException('UPS could not be reached either.');

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_NEEDS_CHECK, $export->getState());
        self::assertSame(LogLevel::ERROR, $this->logger->records[0][0] ?? null);
    }

    /**
     * The test that stops a shipment being paid for twice. Nothing may send it again on its own, from the
     * admin or from anywhere else, while nobody knows whether the carrier issued it.
     */
    public function testAShipmentNobodyKnowsTheFateOfIsNotSentAgain(): void
    {
        $this->existingExport = $this->exportWaitingToBeChecked();

        $this->expectException(AmbiguousShipmentException::class);

        try {
            $this->issuer()->issue($this->shipment(), 'warehouse@example.com');
        } finally {
            self::assertSame([], $this->requests, 'Nothing may reach the carrier a second time.');
        }
    }

    public function testOnceAPersonSaysItWasNeverIssuedItCanBeSentAgain(): void
    {
        $export = $this->exportWaitingToBeChecked();
        $this->existingExport = $export;

        $this->issuer()->confirmNotIssued($export, 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_FAILED, $export->getState());
        self::assertStringContainsString('warehouse@example.com confirmed', (string) $export->getFailureReason());

        $issued = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $issued->getState());
        self::assertCount(1, $this->requests);
    }

    public function testOnlyAShipmentWaitingToBeCheckedIsConfirmedAsNeverIssued(): void
    {
        $export = new CarrierShipmentExport();
        $export->setState(CarrierShipmentExportInterface::STATE_ISSUED);

        $this->expectException(\InvalidArgumentException::class);

        $this->issuer()->confirmNotIssued($export, 'warehouse@example.com');
    }

    /**
     * Overwriting a label that exists would lose the number the parcel is already travelling under, and leave
     * the carrier billing for two shipments.
     */
    public function testAShipmentThatAlreadyHasItsLabelsIsNotIssuedAgain(): void
    {
        $issued = new CarrierShipmentExport();
        $issued->setCarrier('ups');
        $issued->setState(CarrierShipmentExportInterface::STATE_ISSUED);
        $issued->setCarrierReference('1Z999AA10123456784');
        $this->existingExport = $issued;

        try {
            $this->issuer()->issue($this->shipment(), 'warehouse@example.com');
            self::fail('A shipment that already has its labels must not be issued again.');
        } catch (AlreadyIssuedException $exception) {
            self::assertStringContainsString('1Z999AA10123456784', $exception->getMessage());
            self::assertStringContainsString('Cancel them before', $exception->getMessage());
        }

        self::assertSame([], $this->requests, 'Nothing may reach the carrier a second time.');
        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $issued->getState());
        self::assertSame('1Z999AA10123456784', $issued->getCarrierReference());
    }

    /**
     * Once the labels are cancelled with the carrier there is nothing left to overwrite.
     */
    public function testACancelledShipmentIsIssuedAgain(): void
    {
        $voided = new CarrierShipmentExport();
        $voided->setCarrier('ups');
        $voided->setState(CarrierShipmentExportInterface::STATE_VOIDED);
        $voided->setCarrierReference('1Z999AA10123456784');
        $this->existingExport = $voided;

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $export->getState());
        self::assertCount(1, $this->requests);
    }

    public function testAShipmentWhoseIssuingFailedIsIssuedAgain(): void
    {
        $failed = new CarrierShipmentExport();
        $failed->setCarrier('ups');
        $failed->setState(CarrierShipmentExportInterface::STATE_FAILED);
        $failed->setFailureReason('UPS rejected the shipment request: the postcode is not served.');
        $this->existingExport = $failed;

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $export->getState());
        self::assertNull($export->getFailureReason());
    }

    private function exportWaitingToBeChecked(): CarrierShipmentExportInterface
    {
        $export = new CarrierShipmentExport();
        $export->setCarrier('ups');
        $export->setState(CarrierShipmentExportInterface::STATE_NEEDS_CHECK);
        $export->setOwnReference('the first attempt');
        $export->setFailureReason('UPS could not be reached: the request timed out.');

        return $export;
    }

    /**
     * A parcel that never leaves its country is not declared, so the catalogue is asked nothing about it.
     */
    public function testADomesticShipmentDeclaresNothingAndDemandsNothingOfTheCatalogue(): void
    {
        $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertCount(1, $this->requests);
        self::assertFalse($this->requests[0]->crossesABorder());
        self::assertSame([], $this->requests[0]->packages[0]->customsItems);
    }

    public function testAShipmentThatLeavesTheCountryIsDeclaredFromTheCatalogue(): void
    {
        $this->destinationCountry = 'CA';
        $this->declare('MUG', '691200', 'PT');

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $export->getState());
        self::assertTrue($this->requests[0]->crossesABorder());
        $items = $this->requests[0]->packages[0]->customsItems;
        self::assertCount(1, $items);
        self::assertSame(['691200', 'PT', 'Mug', 1, 1200], [
            $items[0]->hsCode, $items[0]->countryOfOrigin, $items[0]->description,
            $items[0]->quantity, $items[0]->unitValue,
        ]);
    }

    /**
     * What the parcel was worth has to survive the shipment, so it is kept on the label and not only sent.
     */
    public function testWhatAParcelWasDeclaredToBeWorthIsKeptWithTheShipment(): void
    {
        $this->destinationCountry = 'CA';
        $this->declare('MUG', '691200', 'PT');

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(1200, $this->requests[0]->packages[0]->declaredValue);
        self::assertSame('USD', $this->requests[0]->packages[0]->declaredValueCurrency);

        $labels = array_values($export->getLabels()->toArray());
        self::assertSame(1200, $labels[0]->getDeclaredValue());
        self::assertSame('USD', $labels[0]->getDeclaredValueCurrency());
    }

    /**
     * A parcel that never leaves its country is not declared, so it is not valued for customs either.
     */
    public function testADomesticParcelIsNotGivenADeclaredValue(): void
    {
        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertNull($this->requests[0]->packages[0]->declaredValue);
        $labels = array_values($export->getLabels()->toArray());
        self::assertNull($labels[0]->getDeclaredValue());
    }

    /**
     * The one that matters: a declaration that cannot be filled in is caught in the warehouse and not at the
     * airport, and the message says which variant and what it is missing.
     */
    public function testAShipmentThatLeavesTheCountryWithAVariantThatCannotBeDeclaredIsNotSent(): void
    {
        $this->destinationCountry = 'CA';
        $this->declare('MUG', '691200', null);

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_FAILED, $export->getState());
        self::assertSame('The variant "MUG" is missing a country of origin.', $export->getFailureReason());
        self::assertSame([], $this->requests, 'Nothing may reach the carrier.');
    }

    public function testAChannelWithoutAShippingOriginIssuesNothing(): void
    {
        $this->origin = null;

        $export = $this->issuer()->issue($this->shipment(), 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_FAILED, $export->getState());
        self::assertStringContainsString('no shipping origin', (string) $export->getFailureReason());
        self::assertSame([], $this->requests);
    }

    public function testAShippingMethodThatNamesNoServiceIssuesNothing(): void
    {
        $shipment = $this->shipment();
        $shipment->getMethod()?->setConfiguration([]);

        $export = $this->issuer()->issue($shipment, 'warehouse@example.com');

        self::assertSame(CarrierShipmentExportInterface::STATE_FAILED, $export->getState());
        self::assertStringContainsString('no carrier service', (string) $export->getFailureReason());
        self::assertSame([], $this->requests);
    }

    public function testAShipmentOfAnotherCalculatorIsNotIssuedAtAll(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->issuer()->issue($this->shipment('flat_rate'), 'warehouse@example.com');
    }

    /**
     * The customs data of the catalogue, which only a shipment that leaves its country ever asks.
     *
     * @return RepositoryInterface<CarrierCustomsDataInterface>&Stub
     */
    private function customsDataRepository(): RepositoryInterface
    {
        /** @var RepositoryInterface<CarrierCustomsDataInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria): ?CarrierCustomsDataInterface {
            $variant = $criteria['variant'] ?? null;

            return $variant instanceof ProductVariantInterface ? ($this->customsData[(string) $variant->getCode()] ?? null) : null;
        });

        return $repository;
    }

    /**
     * The files in the storage, directories left out and in a stable order.
     *
     * @return list<string>
     */
    private function storedFiles(): array
    {
        /** @var list<string> $paths */
        $paths = $this->storage->listContents('', true)
            ->filter(static fn (StorageAttributes $attributes): bool => $attributes->isFile())
            ->map(static fn (StorageAttributes $attributes): string => $attributes->path())
            ->toArray();
        sort($paths);

        return $paths;
    }

    private function issuer(): LabelIssuer
    {
        $calculators = new ServiceRegistry(CalculatorInterface::class);
        $chargeResolver = new ShippingChargeResolver($this->createStub(RateProviderInterface::class));
        $calculators->register('ups_rate', new CarrierRateCalculator($chargeResolver, 'ups', 'ups_rate'));
        $calculators->register('flat_rate', new FlatRateCalculator());

        /** @var RepositoryInterface<CarrierShippingOriginInterface>&Stub $originRepository */
        $originRepository = $this->createStub(RepositoryInterface::class);
        $originRepository->method('findOneBy')->willReturnCallback(fn (): ?CarrierShippingOriginInterface => $this->origin);

        /** @var RepositoryInterface<CarrierShipmentPackagingInterface>&Stub $packagingRepository */
        $packagingRepository = $this->createStub(RepositoryInterface::class);
        $packagingRepository->method('findOneBy')->willReturnCallback(fn (): ?CarrierShipmentPackagingInterface => $this->packaging);

        /** @var RepositoryInterface<CarrierShipmentExportInterface>&Stub $exportRepository */
        $exportRepository = $this->createStub(RepositoryInterface::class);
        $exportRepository->method('findOneBy')->willReturnCallback(fn (): ?CarrierShipmentExportInterface => $this->existingExport);

        /** @var RepositoryInterface<CarrierCredentialsInterface>&Stub $credentialsRepository */
        $credentialsRepository = $this->createStub(RepositoryInterface::class);
        $credentialsRepository->method('findOneBy')->willReturnCallback(fn (): ?CarrierCredentialsInterface => $this->credentials);

        $destinationTypeResolver = $this->createStub(DestinationTypeResolverInterface::class);
        $destinationTypeResolver->method('resolve')->willReturn(DestinationType::RESIDENTIAL);

        /** @var FactoryInterface<CarrierShipmentExportInterface> $exportFactory */
        $exportFactory = new Factory(CarrierShipmentExport::class);
        /** @var FactoryInterface<CarrierShipmentLabelInterface> $labelFactory */
        $labelFactory = new Factory(CarrierShipmentLabel::class);

        return new LabelIssuer(
            new ShipmentCarrier($calculators),
            new ServiceLocator(['ups' => fn (): LabelCarrierInterface => $this->carrier()]),
            new ShipmentRequestFactory(
                $originRepository,
                $packagingRepository,
                $destinationTypeResolver,
                new AddressFactory(),
                new LabelFormats([]),
                new CustomsDataProvider($this->customsDataRepository()),
                new DeclaredValueCalculator(),
            ),
            new CredentialsProvider($credentialsRepository),
            new LabelStorage($this->storage),
            $exportRepository,
            $exportFactory,
            $labelFactory,
            $this->manager(),
            new MockClock('2026-09-21 10:00:00'),
            $this->logger,
        );
    }

    /**
     * Its flush is the moment the rows become real, so a test can say what the storage must look like then, or
     * make it fail there.
     */
    private function manager(): ObjectManager
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('flush')->willReturnCallback(function (): void {
            if (null !== $this->whileCommitting) {
                ($this->whileCommitting)();
            }
        });

        return $manager;
    }

    /**
     * Records what it was asked and answers what the test set, so a test says only what its ending is.
     */
    private function carrier(): LabelCarrierInterface
    {
        return new class(function (ShipmentRequest $request): void {
            $this->requests[] = $request;
        }, $this->answer, $this->recovery, function (string $ownReference): void {
            $this->recovered[] = $ownReference;
        }) implements LabelCarrierInterface {
            /**
             * @param \Closure(ShipmentRequest): void $record
             * @param \Closure(string): void $recordRecovery
             */
            public function __construct(
                private readonly \Closure $record,
                private readonly ShipmentResult|\Throwable $answer,
                private readonly ShipmentResult|\Throwable|null $recovery,
                private readonly \Closure $recordRecovery,
            ) {
            }

            public function ship(ShipmentRequest $request): ShipmentResult
            {
                ($this->record)($request);

                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }

            public function void(string $carrierReference): VoidResult
            {
                throw new \LogicException('Nothing is cancelled while a label is issued.');
            }

            public function recover(string $ownReference): ?ShipmentResult
            {
                ($this->recordRecovery)($ownReference);

                if ($this->recovery instanceof \Throwable) {
                    throw $this->recovery;
                }

                return $this->recovery;
            }
        };
    }

    private function shipment(string $calculator = 'ups_rate'): Shipment
    {
        $channel = new Channel();
        $channel->setCode('WEB');

        $method = new ShippingMethod();
        $method->setCalculator($calculator);
        $method->setConfiguration([CarrierRateCalculator::SERVICE => '03']);

        $address = new Address();
        $address->setStreet('500 Pine St');
        $address->setCity('Seattle');
        $address->setPostcode('98101');
        $address->setCountryCode($this->destinationCountry);
        $address->setProvinceCode('US-WA');
        $address->setFirstName('Grace');
        $address->setLastName('Hopper');
        $address->setPhoneNumber('12065550100');

        $order = new Order();
        $order->setChannel($channel);
        $order->setCurrencyCode('USD');
        $order->setShippingAddress($address);

        $shipment = new Shipment();
        $shipment->setMethod($method);
        $order->addShipment($shipment);

        $id = new \ReflectionProperty(Shipment::class, 'id');
        $id->setValue($shipment, self::SHIPMENT_ID);

        return $shipment;
    }

    private function origin(): CarrierShippingOrigin
    {
        $origin = new CarrierShippingOrigin();
        $origin->setCompanyName('JPM Software Solutions');
        $origin->setContactName('Juan Pablo Moreno Martin');
        $origin->setPhone('13057800955');
        $origin->setStreet('1 Main St');
        $origin->setCity('Miami');
        $origin->setPostcode('33101');
        $origin->setCountryCode('US');
        $origin->setProvinceCode('FL');
        $origin->setWeightUnit('lb');
        $origin->setDimensionUnit('in');

        return $origin;
    }

    /**
     * Deliberately not what any packing would produce today: if these measures reach the carrier, they were read
     * and not recomputed.
     */
    private function storedPackaging(): CarrierShipmentPackagingInterface
    {
        $packaging = new CarrierShipmentPackaging();
        $packaging->addPackage($this->storedPackage(0, 'Medium', 13.5, 11.25, 9.75, 5.5));
        $packaging->addPackage($this->storedPackage(1, null, 20.0, 11.0, 9.0, 9.5));

        return $packaging;
    }

    private function storedPackage(int $position, ?string $boxName, float $length, float $width, float $height, float $weight): CarrierShipmentPackage
    {
        $package = new CarrierShipmentPackage();
        $package->setPosition($position);
        $package->setBoxName($boxName);
        $package->setLength($length);
        $package->setWidth($width);
        $package->setHeight($height);
        $package->setDimensionUnit('in');
        $package->setWeight($weight);
        $package->setWeightUnit('lb');

        $item = new OrderItem();
        $item->setVariant($this->variant());
        $item->setUnitPrice(1200);
        $package->addUnit(new OrderItemUnit($item));

        return $package;
    }

    private function variant(): ProductVariantInterface
    {
        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setCode('MUG_PRODUCT');
        $product->setName('Enamel mug');

        $variant = new ProductVariant();
        $variant->setCurrentLocale('en_US');
        $variant->setFallbackLocale('en_US');
        $variant->setCode('MUG');
        $variant->setName('Mug');
        $variant->setProduct($product);

        return $variant;
    }

    private function declare(string $variantCode, ?string $hsCode, ?string $countryOfOrigin): void
    {
        $customsData = new CarrierCustomsData();
        $customsData->setHsCode($hsCode);
        $customsData->setCountryOfOrigin($countryOfOrigin);

        $this->customsData[$variantCode] = $customsData;
    }

    private function storedCredentials(): CarrierCredentialsInterface
    {
        $credentials = new CarrierCredentials();
        $credentials->setCarrier(CarrierCredentialsInterface::CARRIER_UPS);
        $credentials->setEnvironment('sandbox');
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_DROP_OFF);
        $credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => 'the client id',
            CarrierCredentialsInterface::CLIENT_SECRET => 'the client secret',
            CarrierCredentialsInterface::ACCOUNT_NUMBER => 'A1B2C3',
        ]);

        return $credentials;
    }
}
