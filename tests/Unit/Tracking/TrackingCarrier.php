<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Tracking;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierCallScope;
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

    /** @var list<float|null> How long each call was allowed to take, as the scope said when it was made */
    public array $timeouts = [];

    public ?CarrierCallScope $scope = null;

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
        $this->timeouts[] = $this->scope?->carrierTimeout();

        if ($this->answer instanceof CarrierException) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
