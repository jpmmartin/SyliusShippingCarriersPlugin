<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Customs\Exception;

/**
 * Customs cannot be told what is in the parcel, so it is not sent. The message names the variant and what it
 * is missing: «something is wrong with the customs data» leaves whoever reads it with a catalogue to search.
 */
final class MissingCustomsDataException extends \RuntimeException
{
}
