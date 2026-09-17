<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception;

/**
 * The shipment cannot be packed, so it cannot be quoted. The message is the reason that
 * is logged.
 */
final class UnpackableShipmentException extends \RuntimeException
{
}
