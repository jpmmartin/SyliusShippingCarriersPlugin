<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Label;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelCarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentResult;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\VoidResult;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\NotIssuedException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelVoider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

/**
 * Cancelling has to reach the carrier, and only what the carrier confirms may be written down. A record that
 * says «cancelled» about a shipment that will still be billed is worse than no record at all: the warehouse
 * stops looking for the parcel and the invoice arrives anyway.
 */
final class LabelVoiderTest extends TestCase
{
    private RecordingLogger $logger;

    private VoidResult|\Throwable $answer;

    /** @var list<string> The references the carrier was told to cancel */
    private array $cancelled = [];

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->cancelled = [];
        $this->answer = VoidResult::voided();
    }

    public function testACarrierThatCancelsLeavesTheShipmentCancelledAndSaysWhoAskedAndWhen(): void
    {
        $export = $this->issuedExport();

        $result = $this->voider()->void($export, 'warehouse@example.com');

        self::assertTrue($result->voided);
        self::assertSame(['1Z999AA10123456784'], $this->cancelled, 'The carrier is the one that has to be told.');
        self::assertSame(CarrierShipmentExportInterface::STATE_VOIDED, $export->getState());
        self::assertSame('warehouse@example.com', $export->getVoidedBy());
        self::assertEquals(new \DateTimeImmutable('2026-09-21 10:00:00'), $export->getVoidedAt());
        self::assertNull($export->getFailureReason());
    }

    /**
     * The one that matters: what the carrier will still bill for is never written down as cancelled.
     */
    public function testACarrierThatRefusesLeavesTheLabelsIssuedAndKeepsTheReason(): void
    {
        $this->answer = VoidResult::refused('UPS did not void the shipment: it is past the void window.');
        $export = $this->issuedExport();

        $result = $this->voider()->void($export, 'warehouse@example.com');

        self::assertFalse($result->voided);
        self::assertSame('UPS did not void the shipment: it is past the void window.', $result->reason);
        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $export->getState());
        self::assertSame('UPS did not void the shipment: it is past the void window.', $export->getFailureReason());
        self::assertNull($export->getVoidedAt());
        self::assertNull($export->getVoidedBy());
        self::assertSame(LogLevel::ERROR, $this->logger->records[0][0] ?? null);
    }

    /**
     * A carrier that cannot be asked has said nothing, so nothing is written down either.
     */
    public function testACarrierThatCannotBeAskedChangesNothing(): void
    {
        $this->answer = new CarrierUnavailableException('UPS could not be reached: the request timed out.');
        $export = $this->issuedExport();

        try {
            $this->voider()->void($export, 'warehouse@example.com');
            self::fail('A carrier that cannot be asked has to reach the caller.');
        } catch (CarrierUnavailableException) {
            // Expected.
        }

        self::assertSame(CarrierShipmentExportInterface::STATE_ISSUED, $export->getState());
        self::assertNull($export->getVoidedAt());
    }

    public function testAShipmentWithNoIssuedLabelsIsNotCancelled(): void
    {
        $export = new CarrierShipmentExport();
        $export->setCarrier('ups');
        $export->setState(CarrierShipmentExportInterface::STATE_FAILED);

        $this->expectException(NotIssuedException::class);

        $this->voider()->void($export, 'warehouse@example.com');
    }

    /**
     * Cancelling twice would ask the carrier about a shipment that no longer exists.
     */
    public function testAShipmentThatIsAlreadyCancelledIsNotCancelledAgain(): void
    {
        $export = $this->issuedExport();
        $export->setState(CarrierShipmentExportInterface::STATE_VOIDED);

        try {
            $this->voider()->void($export, 'warehouse@example.com');
            self::fail('A cancelled shipment must not be cancelled again.');
        } catch (NotIssuedException $exception) {
            self::assertStringContainsString('voided', $exception->getMessage());
        }

        self::assertSame([], $this->cancelled);
    }

    public function testAShipmentWithoutACarrierReferenceIsNotCancelled(): void
    {
        $export = $this->issuedExport();
        $export->setCarrierReference(null);

        $this->expectException(NotIssuedException::class);

        $this->voider()->void($export, 'warehouse@example.com');
    }

    private function voider(): LabelVoider
    {
        return new LabelVoider(
            new ServiceLocator(['ups' => fn (): LabelCarrierInterface => $this->carrier()]),
            $this->createStub(ObjectManager::class),
            new MockClock('2026-09-21 10:00:00'),
            $this->logger,
        );
    }

    private function carrier(): LabelCarrierInterface
    {
        return new class(function (string $reference): void {
            $this->cancelled[] = $reference;
        }, $this->answer) implements LabelCarrierInterface {
            /**
             * @param \Closure(string): void $record
             */
            public function __construct(
                private readonly \Closure $record,
                private readonly VoidResult|\Throwable $answer,
            ) {
            }

            public function ship(ShipmentRequest $request): ShipmentResult
            {
                throw new \LogicException('Nothing is issued while labels are cancelled.');
            }

            public function void(string $carrierReference): VoidResult
            {
                ($this->record)($carrierReference);

                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }

            public function recover(string $ownReference): ?ShipmentResult
            {
                throw new \LogicException('Nothing is recovered while labels are cancelled.');
            }
        };
    }

    private function issuedExport(): CarrierShipmentExportInterface
    {
        $export = new CarrierShipmentExport();
        $export->setCarrier('ups');
        $export->setState(CarrierShipmentExportInterface::STATE_ISSUED);
        $export->setCarrierReference('1Z999AA10123456784');

        return $export;
    }
}
