<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\BatchIssuer;
use JpmMartin\SyliusShippingCarriersPlugin\Label\BatchResult;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AlreadyIssuedException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelIssuerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

/**
 * A batch is worth having only if one shipment going wrong does not take the rest with it: the whole point is
 * printing a morning's worth of labels in one go.
 */
final class BatchIssuerTest extends TestCase
{
    private RecordingLogger $logger;

    /** @var array<int, CarrierShipmentExportInterface|\Throwable> What issuing each shipment does, by id */
    private array $outcomes = [];

    /** @var list<int> The shipments that were handed over, in the order they were */
    private array $issued = [];

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->outcomes = [];
        $this->issued = [];
    }

    public function testEveryShipmentOfTheBatchIsIssued(): void
    {
        $shipments = [$this->shipment(1), $this->shipment(2), $this->shipment(3)];

        $results = $this->batchIssuer()->issue($shipments, 'warehouse@example.com');

        self::assertCount(3, $results);
        self::assertSame([1, 2, 3], $this->issued);
        foreach ($results as $result) {
            self::assertTrue($result->wasIssued());
            self::assertNull($result->reason());
        }
    }

    /**
     * The test the batch exists for: the one that fails answers for itself and the rest go out.
     */
    public function testAShipmentThatFailsDoesNotStopTheRestAndEachOneAnswersForItself(): void
    {
        $this->outcomes[2] = $this->export(CarrierShipmentExportInterface::STATE_FAILED, 'UPS rejected the shipment request: the postcode is not served.');
        $shipments = [$this->shipment(1), $this->shipment(2), $this->shipment(3)];

        $results = $this->batchIssuer()->issue($shipments, 'warehouse@example.com');

        self::assertSame([true, false, true], array_map(static fn (BatchResult $result): bool => $result->wasIssued(), $results));
        self::assertSame([1, 2, 3], $this->issued, 'Every shipment is still handed over.');
        self::assertSame('UPS rejected the shipment request: the postcode is not served.', $results[1]->reason());
        self::assertSame([$shipments[0], $shipments[1], $shipments[2]], array_map(static fn (BatchResult $result): ShipmentInterface => $result->shipment, $results));
    }

    /**
     * A shipment in no state to be issued throws rather than answering, and that must not take the batch down
     * either.
     */
    public function testAShipmentInNoStateToBeIssuedIsRefusedAndTheRestGoOut(): void
    {
        $this->outcomes[1] = new AlreadyIssuedException('The shipment already has its labels.');
        $shipments = [$this->shipment(1), $this->shipment(2)];

        $results = $this->batchIssuer()->issue($shipments, 'warehouse@example.com');

        self::assertFalse($results[0]->wasIssued());
        self::assertSame('The shipment already has its labels.', $results[0]->reason());
        self::assertNull($results[0]->export);
        self::assertTrue($results[1]->wasIssued());
        self::assertSame(LogLevel::ERROR, $this->logger->records[0][0] ?? null);
    }

    public function testAShipmentNobodyKnowsTheFateOfIsNeitherIssuedNorAPlainFailure(): void
    {
        $this->outcomes[1] = $this->export(CarrierShipmentExportInterface::STATE_NEEDS_CHECK, 'UPS could not be reached: the request timed out.');

        $results = $this->batchIssuer()->issue([$this->shipment(1)], 'warehouse@example.com');

        self::assertFalse($results[0]->wasIssued());
        self::assertTrue($results[0]->needsCheck());
        self::assertSame('UPS could not be reached: the request timed out.', $results[0]->reason());
    }

    public function testAnEmptyBatchIssuesNothing(): void
    {
        self::assertSame([], $this->batchIssuer()->issue([], 'warehouse@example.com'));
        self::assertSame([], $this->issued);
    }

    private function batchIssuer(): BatchIssuer
    {
        return new BatchIssuer($this->labelIssuer(), $this->logger);
    }

    /**
     * Records the order the shipments were handed over in, so the test can say they were handed over one
     * after another and not all at once.
     */
    private function labelIssuer(): LabelIssuerInterface
    {
        return new class(
            function (ShipmentInterface $shipment): CarrierShipmentExportInterface {
            $this->issued[] = (int) $shipment->getId();
            $outcome = $this->outcomes[$shipment->getId()] ?? null;

            if ($outcome instanceof \Throwable) {
                throw $outcome;
            }

            return $outcome ?? $this->export(CarrierShipmentExportInterface::STATE_ISSUED);
        },
        ) implements LabelIssuerInterface {
            /**
             * @param \Closure(ShipmentInterface): CarrierShipmentExportInterface $issue
             */
            public function __construct(
                private readonly \Closure $issue,
            ) {
            }

            public function issue(ShipmentInterface $shipment, string $issuedBy): CarrierShipmentExportInterface
            {
                return ($this->issue)($shipment);
            }

            public function confirmNotIssued(CarrierShipmentExportInterface $export, string $confirmedBy): void
            {
                throw new \LogicException('Nothing is confirmed while a batch is issued.');
            }
        };
    }

    /**
     * @param CarrierShipmentExportInterface::STATE_* $state
     */
    private function export(string $state, ?string $reason = null): CarrierShipmentExportInterface
    {
        $export = new CarrierShipmentExport();
        $export->setCarrier('ups');
        $export->setState($state);
        $export->setFailureReason($reason);

        return $export;
    }

    private function shipment(int $id): ShipmentInterface
    {
        $order = new Order();
        $shipment = new Shipment();
        $order->addShipment($shipment);

        (new \ReflectionProperty(Shipment::class, 'id'))->setValue($shipment, $id);

        return $shipment;
    }
}
