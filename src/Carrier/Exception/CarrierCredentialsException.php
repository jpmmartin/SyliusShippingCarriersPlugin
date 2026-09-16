<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception;

/**
 * The carrier cannot be called with the stored credentials: there are none, or the carrier rejected them.
 */
final class CarrierCredentialsException extends CarrierException
{
}
