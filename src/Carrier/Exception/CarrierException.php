<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception;

/**
 * The only failure a carrier adapter lets out. Every exception of a carrier SDK, of the HTTP client or of
 * serialization is translated to one of its subclasses inside `Carrier/`, so no caller ever depends on an
 * SDK (CA-23).
 */
abstract class CarrierException extends \RuntimeException
{
}
