<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional;

use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Stands in for the rate provider in a running application: every shipment gets the result a test sets. The real
 * provider is reset between requests, so this one must accept it too.
 */
final class FakeRateProvider implements RateProviderInterface, ResetInterface
{
    public function __construct(
        public RateResult $result,
    ) {
    }

    public function rateFor(ShipmentInterface $shipment, string $carrier, string $serviceCode): RateResult
    {
        return $this->result;
    }

    public function reset(): void
    {
    }
}
