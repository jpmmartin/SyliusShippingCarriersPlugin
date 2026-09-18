<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

/**
 * What the carrier answered when asked to cancel a shipment.
 *
 * A refusal is not a failure of the plugin: the label stays issued, and saying so is the whole point. Silence
 * here is what makes a warehouse ship a parcel it believes was cancelled.
 */
final readonly class VoidResult
{
    private function __construct(
        public bool $voided,
        public ?string $reason,
    ) {
    }

    public static function voided(): self
    {
        return new self(true, null);
    }

    /**
     * The carrier refused, and said why. The shipment is still issued.
     */
    public static function refused(string $reason): self
    {
        return new self(false, $reason);
    }
}
