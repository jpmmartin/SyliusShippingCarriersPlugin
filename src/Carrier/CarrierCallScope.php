<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettings;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The settings of the call to a carrier being made now, for the channel it is made for.
 *
 * The carrier interfaces take a tracking number or a reference and nothing else, and they are public, so a
 * channel's timeout cannot travel through them. Whatever calls a carrier for an order runs the call within the
 * settings of that order's channel, and the clients the carriers are called through read them from here. Outside a
 * call, the configuration's apply, as those clients were given them.
 *
 * @internal
 */
final class CarrierCallScope implements ResetInterface
{
    private ?CarrierSettings $current = null;

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    public function within(CarrierSettings $settings, \Closure $call): mixed
    {
        // Kept and put back, so a call made inside another is not left with the settings of the one it was in.
        $previous = $this->current;
        $this->current = $settings;

        try {
            return $call();
        } finally {
            $this->current = $previous;
        }
    }

    /**
     * Seconds the call being made now may take, or null outside a call.
     */
    public function carrierTimeout(): ?float
    {
        return $this->current?->carrierTimeout;
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
