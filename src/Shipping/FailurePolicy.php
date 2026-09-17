<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Shipping;

/**
 * What a shipping method does when its carrier fails to rate it.
 */
final class FailurePolicy
{
    /** The shipping method is not offered. The default. */
    public const HIDE = 'hide';

    /** The shipping method is offered at the flat amount set for the channel. */
    public const FLAT = 'flat';

    public const ALL = [self::HIDE, self::FLAT];
}
