<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception;

/**
 * The carrier answered, but refused the request, for instance an address it does not serve.
 */
final class CarrierRejectedRequestException extends CarrierException
{
}
