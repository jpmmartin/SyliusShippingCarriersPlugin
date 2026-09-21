<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label\Exception;

/**
 * The shipment already has its labels, so it is not issued again. Reissuing means cancelling the labels that
 * exist first: overwriting them would leave a parcel going out under a number nobody can cancel and a carrier
 * billing for two shipments.
 */
final class AlreadyIssuedException extends \RuntimeException
{
}
