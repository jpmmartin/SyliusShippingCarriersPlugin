<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateSet;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;

/**
 * The port every carrier adapter implements. Nothing from a carrier SDK crosses it, in either direction:
 * requests and results are the plugin's own types, and every failure is a CarrierException (CA-23).
 */
interface CarrierInterface
{
    /**
     * Every service the carrier offers for the request, in a single call (CA-8).
     *
     * @throws CarrierException
     */
    public function rate(RateRequest $request): RateSet;

    /**
     * @throws CarrierException
     */
    public function track(string $trackingNumber): TrackingInfo;
}
