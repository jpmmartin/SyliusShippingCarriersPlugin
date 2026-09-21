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
     * The question is asked with the reference the plugin put on the request, not with a tracking number: a
     * shipment nobody got an answer for has no tracking number, which is the whole of the problem.
     *
     * Null is not a failure: it means this carrier cannot answer that question, or does not know the
     * reference, so the ambiguity needs a person. UPS can answer it; FedEx has no equivalent operation.
     *
     * @throws CarrierException When the carrier could not be asked
     */
    public function recover(string $ownReference): ?ShipmentResult;
}
