<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;

/**
 * A carrier that issues labels.
 *
 * Separate from the interface that quotes rates on purpose: a carrier may be able to quote without issuing,
 * and what already works for rates must not have to change for labels to exist.
 */
interface LabelCarrierInterface
{
    /**
     * @throws CarrierException When the carrier refused, or gave no usable answer. The difference between the
     *                          two is the difference between a failure and a shipment that may have been paid
     *                          for, and it is what the exception says
     */
    public function ship(ShipmentRequest $request): ShipmentResult;

    /**
     * @throws CarrierException When the carrier could not be asked at all. A carrier that answers «no» is a
     *                          refusal, not an exception
     */
    public function void(string $carrierReference): VoidResult;

    /**
     * Asks the carrier whether it did issue a shipment nobody got an answer for.
     *
     * Null is not a failure: it means this carrier cannot answer that question, so the ambiguity needs a
     * person. UPS can answer it; FedEx has no equivalent operation.
     *
     * @throws CarrierException When the carrier could not be asked
     */
    public function recover(string $carrierReference): ?ShipmentResult;
}
