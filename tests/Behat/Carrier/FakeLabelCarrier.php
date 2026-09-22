<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsDocument;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\IssuedLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelCarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentResult;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\VoidResult;

/**
 * Stands in for the labels of UPS or FedEx in the test environment, the way {@see FakeCarrier} stands in for
 * their rates: it answers what a scenario told it to and records what it was asked to do.
 *
 * Issuing is the one operation of this plugin that spends the merchant's money, so no test may reach the real
 * carrier by accident. This is what makes that impossible rather than unlikely.
 */
final readonly class FakeLabelCarrier implements LabelCarrierInterface
{
    public function __construct(
        private string $carrier,
        private FakeCarrierState $state,
    ) {
    }

    public function ship(ShipmentRequest $request): ShipmentResult
    {
        $this->state->recordShipCall($this->carrier);

        $this->failAsTold();

        $shipment = $this->state->shipment($this->carrier);
        if (null === $shipment) {
            throw new UnexpectedCarrierResponseException(sprintf('No scenario said what %s issues.', $this->carrier));
        }

        $labels = [];
        foreach ($request->packages as $position => $package) {
            $labels[] = new IssuedLabel(
                $position,
                self::trackingNumber($shipment['reference'], $position),
                $shipment['format'],
                sprintf('the label of package %d', $position),
            );
        }

        // A shipment that crosses a border asks for its invoice in the same request, so it comes back in the
        // same answer. A domestic one asks for nothing and gets nothing.
        $customsDocument = null === $request->customsInvoice
            ? null
            : new CustomsDocument('PDF', sprintf('the invoice of %s', $shipment['reference']));

        return new ShipmentResult($shipment['reference'], $labels, $customsDocument);
    }

    public function void(string $carrierReference): VoidResult
    {
        $this->failAsTold();

        $refusal = $this->state->voidRefusal($this->carrier);
        if (null !== $refusal) {
            return VoidResult::refused($refusal);
        }

        $this->state->recordVoid($this->carrier, $carrierReference);

        return VoidResult::voided();
    }

    /**
     * Null, the same as the real FedEx adapter: until somebody shows that a carrier can be asked about a
     * shipment by the reference the plugin gave it, nothing may assume it was issued.
     */
    public function recover(string $ownReference): ?ShipmentResult
    {
        return null;
    }

    /**
     * The first package carries the number the carrier calls the whole shipment; the rest hang off it.
     */
    private static function trackingNumber(string $reference, int $position): string
    {
        return 0 === $position ? $reference : sprintf('%s%d', $reference, $position);
    }

    private function failAsTold(): void
    {
        match ($this->state->failure($this->carrier)) {
            FakeCarrierState::FAILURE_TIMEOUT => throw new CarrierUnavailableException(sprintf('%s did not answer in time.', $this->carrier)),
            FakeCarrierState::FAILURE_SERVER_ERROR => throw new CarrierUnavailableException(sprintf('%s answered with a server error.', $this->carrier)),
            FakeCarrierState::FAILURE_UNREADABLE => throw new UnexpectedCarrierResponseException(sprintf('%s answered with something that cannot be read.', $this->carrier)),
            FakeCarrierState::FAILURE_CREDENTIALS => throw new CarrierCredentialsException(sprintf('%s rejected the credentials.', $this->carrier)),
            null => null,
        };
    }
}
