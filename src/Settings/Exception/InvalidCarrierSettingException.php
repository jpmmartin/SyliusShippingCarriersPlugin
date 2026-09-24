<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception;

/**
 * A setting of the plugin has a value it cannot work with. A value written in the configuration never gets this
 * far, because the container refuses to compile; one given by an environment variable is only known when the store
 * runs, so it is refused where it would have been used. The message names the setting and the value.
 */
final class InvalidCarrierSettingException extends \RuntimeException
{
}
