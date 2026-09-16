<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Rate;

/**
 * Every rate a single carrier call returned, one per service (CA-8).
 */
final readonly class RateSet
{
    /** @var array<string, Rate> */
    private array $rates;

    /**
     * @param list<Rate> $rates
     */
    public function __construct(array $rates)
    {
        $byService = [];
        foreach ($rates as $rate) {
            $byService[$rate->serviceCode] = $rate;
        }

        $this->rates = $byService;
    }

    /** Null when the carrier does not offer that service for the shipment. */
    public function get(string $serviceCode): ?Rate
    {
        return $this->rates[$serviceCode] ?? null;
    }

    /**
     * @return list<Rate>
     */
    public function all(): array
    {
        return array_values($this->rates);
    }
}
