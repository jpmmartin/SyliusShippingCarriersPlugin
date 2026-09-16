<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception;

/**
 * The shipment cannot be packed, so it cannot be quoted (CA-17, CA-40). The message is the reason that
 * is logged.
 */
final class UnpackableShipmentException extends \RuntimeException
{
}
