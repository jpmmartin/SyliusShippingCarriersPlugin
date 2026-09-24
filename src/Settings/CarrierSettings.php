<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Settings;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception\InvalidCarrierSettingException;

/**
 * The settings the plugin works with, as they are when the store runs.
 *
 * A value written in the configuration was checked when the container was compiled. One given by an environment
 * variable could not be, so each part of the plugin checks the values it is about to use and refuses to go on with
 * one it would have been refused: a retention of zero would have the purge delete every document, and a timeout of
 * zero would leave a request to a carrier waiting for as long as the carrier likes.
 *
 * @internal
 */
final class CarrierSettings
{
    public const MIN_CARRIER_TIMEOUT = 0.1;

    public const MIN_SECONDS = 1;

    /**
     * @param array<string, string> $labelFormats By carrier code
     */
    public function __construct(
        public readonly float $carrierTimeout,
        public readonly int $rateLifetime,
        public readonly int $rateRetention,
        public readonly int $trackingLifetime,
        public readonly int $documentsRetention,
        public readonly int $temporaryDocumentsRetention,
        public readonly array $labelFormats,
    ) {
    }

    /**
     * @throws InvalidCarrierSettingException
     */
    public function assertRatesUsable(): void
    {
        $this->assertCarrierCallsUsable();
        self::assertSeconds('rate_lifetime', $this->rateLifetime);
        self::assertSeconds('rate_retention', $this->rateRetention);

        if ($this->rateRetention < $this->rateLifetime) {
            throw new InvalidCarrierSettingException(sprintf(
                'rate_retention is %d and rate_lifetime is %d: the retention cannot be less than the lifetime, or a rate would be gone before it expired.',
                $this->rateRetention,
                $this->rateLifetime,
            ));
        }
    }

    /**
     * @throws InvalidCarrierSettingException
     */
    public function assertTrackingUsable(): void
    {
        $this->assertCarrierCallsUsable();
        self::assertSeconds('tracking_lifetime', $this->trackingLifetime);
    }

    /**
     * @throws InvalidCarrierSettingException
     */
    public function assertPurgeUsable(): void
    {
        self::assertSeconds('documents_retention', $this->documentsRetention);
        self::assertSeconds('temporary_documents_retention', $this->temporaryDocumentsRetention);
    }

    /**
     * @throws InvalidCarrierSettingException
     */
    public function assertLabelsUsable(string $carrier): void
    {
        $this->assertCarrierCallsUsable();

        $format = $this->labelFormat($carrier);
        $supported = LabelFormats::SUPPORTED[$carrier] ?? [];
        if (!in_array($format, $supported, true)) {
            throw new InvalidCarrierSettingException(sprintf(
                'label_formats.%s is "%s", which the carrier does not print labels as. It offers %s.',
                $carrier,
                $format,
                implode(', ', $supported),
            ));
        }
    }

    /**
     * @throws InvalidCarrierSettingException
     */
    public function assertCarrierCallsUsable(): void
    {
        if ($this->carrierTimeout < self::MIN_CARRIER_TIMEOUT) {
            throw new InvalidCarrierSettingException(sprintf(
                'carrier_timeout is %s, and it cannot be less than %s seconds.',
                $this->carrierTimeout,
                self::MIN_CARRIER_TIMEOUT,
            ));
        }
    }

    public function labelFormat(string $carrier): string
    {
        return $this->labelFormats[$carrier] ?? LabelFormats::DEFAULTS[$carrier] ?? 'PDF';
    }

    private static function assertSeconds(string $setting, int $seconds): void
    {
        if ($seconds < self::MIN_SECONDS) {
            throw new InvalidCarrierSettingException(sprintf(
                '%s is %d, and it cannot be less than %d second.',
                $setting,
                $seconds,
                self::MIN_SECONDS,
            ));
        }
    }
}
