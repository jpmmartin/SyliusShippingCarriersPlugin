<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Tracking;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateSet;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;

/**
 * A carrier that keeps every tracking number it is asked about, and answers what it is given.
 */
final class TrackingCarrier implements CarrierInterface
{
    /** @var list<string> */
    public array $enquiries = [];

    public function __construct(
        public TrackingInfo|CarrierException $answer,
    ) {
    }

    public function rate(RateRequest $request): RateSet
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        $this->enquiries[] = $trackingNumber;

        if ($this->answer instanceof CarrierException) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
