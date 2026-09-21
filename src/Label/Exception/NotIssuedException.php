<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label\Exception;

/**
 * There is nothing to cancel: the shipment has no labels issued, or they were cancelled already. Cancelling
 * twice would ask the carrier about a shipment that no longer exists and tell the warehouse something happened
 * that did not.
 */
final class NotIssuedException extends \RuntimeException
{
}
