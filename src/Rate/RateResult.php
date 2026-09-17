<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Rate;

/**
 * What asking for the rate of a service gave: a rate to quote, a failure of the carrier, or nothing to quote
 * because the shipment cannot be rated with that service at all.
 *
 * The difference matters to a shipping method: a flat amount stands in for the carrier only when the carrier
 * fails, never for a service it does not offer or a shipment without an address.
 */
final readonly class RateResult
{
    private function __construct(
        /** The rate to charge, in the currency of the order. */
        public ?Rate $rate,
        public bool $carrierFailed,
        /**
         * On a failure of the carrier only: the last rate it gave for the same shipment, even if it is no
         * longer quoted, while it is retained.
         */
        public ?Rate $lastKnownRate,
    ) {
    }

    public static function quoted(Rate $rate): self
    {
        return new self($rate, false, null);
    }

    public static function carrierFailed(?Rate $lastKnownRate): self
    {
        return new self(null, true, $lastKnownRate);
    }

    /**
     * No address to ship to, no origin for the channel, units that cannot be packed, a service the carrier does
     * not offer for the shipment, or a rate in a currency without an exchange rate.
     */
    public static function unavailable(): self
    {
        return new self(null, false, null);
    }
}
