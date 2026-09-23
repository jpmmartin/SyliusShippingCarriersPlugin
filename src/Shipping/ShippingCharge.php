<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Shipping;

/**
 * What a shipping method of a carrier charges for a shipment, and where the amount comes from.
 *
 * @internal
 */
final readonly class ShippingCharge
{
    /** A rate the carrier gave for this shipment and that is still fresh. */
    public const SOURCE_RATE = 'rate';

    /** The carrier failed; the last rate it gave for the same shipment, kept for a while. */
    public const SOURCE_LAST_KNOWN = 'last_known';

    /** The carrier failed without a rate to fall back on; the flat amount set for the channel. */
    public const SOURCE_FLAT = 'flat';

    /**
     * @param int $amount In the currency of the order, as Sylius keeps amounts
     * @param self::SOURCE_* $source
     */
    public function __construct(
        public int $amount,
        public string $source,
    ) {
    }
}
