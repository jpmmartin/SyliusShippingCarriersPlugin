<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Settings;

use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettings;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;

/**
 * The plugin's settings with its defaults, changing only what a test is about.
 */
final class CarrierSettingsFactory
{
    /**
     * @param array<string, string> $labelFormats
     */
    public static function provider(
        float $carrierTimeout = 10.0,
        int $rateLifetime = 900,
        int $rateRetention = 86400,
        int $trackingLifetime = 300,
        int $documentsRetention = 180 * 24 * 60 * 60,
        int $temporaryDocumentsRetention = 24 * 60 * 60,
        array $labelFormats = [],
    ): CarrierSettingsProvider {
        return new CarrierSettingsProvider(new CarrierSettings(
            $carrierTimeout,
            $rateLifetime,
            $rateRetention,
            $trackingLifetime,
            $documentsRetention,
            $temporaryDocumentsRetention,
            $labelFormats,
        ));
    }
}
