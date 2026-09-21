<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label\Exception;

/**
 * The shipment cannot be handed to a carrier at all, so nothing is asked of one. The message is the reason the
 * operator is shown and the reason that is recorded.
 */
final class UnissuableShipmentException extends \RuntimeException
{
}
