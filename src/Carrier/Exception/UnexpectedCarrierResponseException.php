<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception;

/**
 * The carrier answered with something the adapter cannot read: an unreadable body, or one without the
 * data it needs.
 */
final class UnexpectedCarrierResponseException extends CarrierException
{
}
