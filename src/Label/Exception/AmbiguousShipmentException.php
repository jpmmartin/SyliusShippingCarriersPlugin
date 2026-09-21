<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label\Exception;

/**
 * The shipment was handed to its carrier once and nobody knows whether it was issued, so it is not handed over
 * again. This is the refusal that stops the same parcel being paid for twice; it clears when a person confirms
 * the carrier never issued it.
 */
final class AmbiguousShipmentException extends \RuntimeException
{
}
