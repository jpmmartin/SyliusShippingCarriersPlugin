<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateSet;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;

/**
 * Stands in for UPS or FedEx in the test environment: it answers what a scenario told it to, with the exception the
 * real adapter would throw for each failure, and counts how often it is asked.
 */
final readonly class FakeCarrier implements CarrierInterface
{
    public function __construct(
        private string $carrier,
        private FakeCarrierState $state,
    ) {
    }

    public function rate(RateRequest $request): RateSet
    {
        $this->state->recordCall($this->carrier);

        match ($this->state->failure($this->carrier)) {
            FakeCarrierState::FAILURE_TIMEOUT => throw new CarrierUnavailableException(sprintf('%s did not answer in time.', $this->carrier)),
            FakeCarrierState::FAILURE_SERVER_ERROR => throw new CarrierUnavailableException(sprintf('%s answered with a server error.', $this->carrier)),
            FakeCarrierState::FAILURE_UNREADABLE => throw new UnexpectedCarrierResponseException(sprintf('%s answered with something that cannot be read.', $this->carrier)),
            FakeCarrierState::FAILURE_CREDENTIALS => throw new CarrierCredentialsException(sprintf('%s rejected the credentials.', $this->carrier)),
            null => null,
        };

        $rates = [];
        foreach ($this->state->rates($this->carrier) as $serviceCode => $rate) {
            $rates[] = new Rate((string) $serviceCode, $rate['amount'], $rate['currency']);
        }

        return new RateSet($rates);
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        throw new CarrierUnavailableException(sprintf('The fake %s does not track shipments.', $this->carrier));
    }
}
