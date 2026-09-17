<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Rate;

/**
 * Every rate one carrier call gave, with the time it gave them, as kept in the rate cache.
 *
 * The cache holds plain arrays rather than serialized objects of the plugin, so an update that changes these
 * classes never leaves entries behind that cannot be read.
 *
 * @internal
 */
final readonly class StoredRates
{
    public function __construct(
        public RateSet $rates,
        /** Unix time of the carrier's answer. */
        public int $fetchedAt,
    ) {
    }

    /**
     * Null when the cache holds something else, such as an entry written by another version of the plugin.
     */
    public static function fromCacheValue(mixed $value): ?self
    {
        if (!is_array($value) || !is_int($value['fetched_at'] ?? null) || !is_array($value['rates'] ?? null)) {
            return null;
        }

        $rates = [];
        foreach ($value['rates'] as $rate) {
            if (!is_array($rate) || !is_string($rate['service'] ?? null) || !is_int($rate['amount'] ?? null) || !is_string($rate['currency'] ?? null)) {
                return null;
            }

            $rates[] = new Rate($rate['service'], $rate['amount'], $rate['currency']);
        }

        return new self(new RateSet($rates), $value['fetched_at']);
    }

    /**
     * @return array{fetched_at: int, rates: list<array{service: string, amount: int, currency: string}>}
     */
    public function toCacheValue(): array
    {
        return [
            'fetched_at' => $this->fetchedAt,
            'rates' => array_map(
                static fn (Rate $rate): array => ['service' => $rate->serviceCode, 'amount' => $rate->amount, 'currency' => $rate->currencyCode],
                $this->rates->all(),
            ),
        ];
    }
}
