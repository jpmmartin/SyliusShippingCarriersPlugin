<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

/**
 * How long is left to cancel a shipment with its carrier.
 *
 * It exists because the two carriers are nothing alike: ninety days against twelve hours. An operator used to
 * UPS working a FedEx shipment finds out too late unless the screen says so.
 *
 * @internal
 */
final readonly class VoidWindowState
{
    /**
     * @param \DateTimeImmutable $selfServiceUntil Until when the plugin itself can cancel it
     * @param \DateTimeImmutable|null $assistedUntil Until when the carrier still cancels it, but only by
     *                                               talking to them. Null when it has no such period
     * @param int $secondsLeft Of the self-service window. Zero once it is over
     */
    public function __construct(
        public \DateTimeImmutable $selfServiceUntil,
        public ?\DateTimeImmutable $assistedUntil,
        public int $secondsLeft,
    ) {
    }

    /**
     * Whether cancelling from here still works.
     */
    public function isOpen(): bool
    {
        return $this->secondsLeft > 0;
    }

    /**
     * The carrier would still cancel it, but somebody has to ring them: the plugin no longer can.
     */
    public function needsTheCarrierItself(\DateTimeImmutable $now): bool
    {
        return !$this->isOpen() && null !== $this->assistedUntil && $now < $this->assistedUntil;
    }
}
