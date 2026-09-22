<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShipmentCarrier;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * Issues the labels of several shipments, one after another.
 *
 * Two things matter here and both are about not losing money. A shipment that cannot be issued must not stop
 * the ones behind it, so every shipment is issued on its own and answers for itself. And the calls are never
 * made in parallel: both SDKs hold their own rate limits back, and firing shipment requests at a carrier all
 * at once is the quickest way to be cut off with money already in flight.
 */
final readonly class BatchIssuer implements BatchIssuerInterface
{
    public function __construct(
        private LabelIssuerInterface $labelIssuer,
        private ShipmentCarrier $shipmentCarrier,
        private LoggerInterface $logger,
    ) {
    }

    public function issue(iterable $shipments, string $issuedBy): array
    {
        $results = [];

        foreach ($shipments as $shipment) {
            $results[] = $this->issueOne($shipment, $issuedBy);
        }

        return $results;
    }

    private function issueOne(ShipmentInterface $shipment, string $issuedBy): BatchResult
    {
        try {
            return BatchResult::of($shipment, $this->labelIssuer->issue($shipment, $issuedBy));
        } catch (\Throwable $exception) {
            // Every shipment answers for itself: whatever went wrong with this one, the rest are still issued.
            $this->logger->error('The labels of the shipment {shipment} were not issued by {carrier} in the batch: {reason}', [
                'carrier' => $this->shipmentCarrier->of($shipment),
                'shipment' => $shipment->getId(),
                'reason' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return BatchResult::refused($shipment, $exception->getMessage());
        }
    }
}
