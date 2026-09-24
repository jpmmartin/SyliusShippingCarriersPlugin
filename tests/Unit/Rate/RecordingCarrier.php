<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Rate;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierCallScope;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateSet;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;

/**
 * A carrier that keeps every request it is asked to rate, and answers with the rates or the failure it is given.
 */
final class RecordingCarrier implements CarrierInterface
{
    /** @var list<RateRequest> */
    public array $requests = [];

    /** @var list<float|null> How long each call was allowed to take, as the scope said when it was made */
    public array $timeouts = [];

    public ?CarrierCallScope $scope = null;

    public function __construct(
        public RateSet|CarrierException $answer,
    ) {
    }

    public function rate(RateRequest $request): RateSet
    {
        $this->requests[] = $request;
        $this->timeouts[] = $this->scope?->carrierTimeout();

        if ($this->answer instanceof CarrierException) {
            throw $this->answer;
        }

        return $this->answer;
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        throw new \LogicException('Not used by these tests.');
    }
}
