<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Rate;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;

/**
 * The key rates are cached under. It holds what the price depends on and nothing about the buyer or the
 * session, so two buyers of a channel sending the same packages to the same place share the rates.
 *
 * The channel is part of it: each channel keeps rates for as long as it says, and one that keeps them for less
 * would otherwise drop the last known rate of another that keeps it for longer.
 *
 * Of the destination only the country, province, postcode, city and whether it is a home count: carriers
 * price by place, not by street. Of the origin the whole address counts, so editing it asks again.
 *
 * @internal
 */
final class RateCacheKey
{
    /**
     * @param string $carrier The carrier the rates come from
     * @param array<string, string|null> $carrierConfiguration What else changes the carrier's price, such as the
     *                                                         environment, the pickup type or the account; never
     *                                                         a secret
     * @param string $currencyCode The currency of the order being quoted
     * @param string|null $channelCode The channel of the order being quoted
     */
    public static function for(string $carrier, array $carrierConfiguration, RateRequest $request, string $currencyCode, ?string $channelCode): string
    {
        ksort($carrierConfiguration);

        $origin = $request->origin;
        $destination = $request->destination;

        $fingerprint = [
            'carrier' => $carrier,
            'configuration' => $carrierConfiguration,
            'origin' => [$origin->countryCode, $origin->provinceCode, $origin->postcode, $origin->city, $origin->street],
            'destination' => [$destination->countryCode, $destination->provinceCode, $destination->postcode, $destination->city, $destination->residential],
            'packages' => array_map(
                static fn (Package $package): array => [$package->length, $package->width, $package->height, $package->dimensionUnit, $package->weight, $package->weightUnit],
                $request->packages,
            ),
            'currency' => $currencyCode,
            'channel' => $channelCode,
        ];

        // PSR-6 only guarantees keys of up to 64 letters, digits, underscores and dots. A fast 128-bit hash
        // fits with room to spare; it only has to tell rates apart, not resist an attacker.
        return 'rate_' . hash('xxh128', json_encode($fingerprint, \JSON_THROW_ON_ERROR));
    }
}
