<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional;

use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingProviderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Stands in for the tracking provider in a running application: every shipment gets the answer a test sets, and the
 * enquiries are counted. The real provider is reset between requests, so this one must accept it too — without
 * forgetting what a test is about to assert.
 */
final class FakeTrackingProvider implements TrackingProviderInterface, ResetInterface
{
    public ?TrackingInfo $answer = null;

    public int $enquiries = 0;

    public function track(ShipmentInterface $shipment): ?TrackingInfo
    {
        ++$this->enquiries;

        return $this->answer;
    }

    public function reset(): void
    {
    }
}
