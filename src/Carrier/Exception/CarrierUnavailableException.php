<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception;

/**
 * The carrier could not answer: a timeout, a network error or a server error on its side.
 */
final class CarrierUnavailableException extends CarrierException
{
}
