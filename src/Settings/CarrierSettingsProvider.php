<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Settings;

/**
 * Where every part of the plugin asks for the settings it works with, instead of each reading its own parameters.
 *
 * @internal
 */
final class CarrierSettingsProvider
{
    public function __construct(
        private readonly CarrierSettings $defaults,
    ) {
    }

    /**
     * The settings of the whole store, as its configuration gives them.
     */
    public function defaults(): CarrierSettings
    {
        return $this->defaults;
    }
}
