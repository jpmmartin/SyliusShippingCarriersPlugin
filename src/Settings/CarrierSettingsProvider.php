<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Settings;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierChannelSettingsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception\InvalidCarrierSettingException;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Where every part of the plugin asks for the settings it works with, instead of each reading its own parameters.
 *
 * A channel's settings are what its shipping origin says, and the configuration's for whatever the origin leaves
 * empty. Nothing is checked here: each part checks the values it is about to use, so a value that is wrong for one
 * of them stops that one and no other.
 *
 * @internal
 */
final class CarrierSettingsProvider implements ResetInterface
{
    /**
     * Resolved once per channel and request: rates ask once for every service of a shipment. Forgotten between
     * requests, because an administrator may have changed them.
     *
     * @var array<string, CarrierSettings>
     */
    private array $byChannel = [];

    /**
     * @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository
     */
    public function __construct(
        private readonly CarrierSettings $defaults,
        private readonly RepositoryInterface $originRepository,
    ) {
    }

    /**
     * The settings of the whole store, as its configuration gives them.
     */
    public function defaults(): CarrierSettings
    {
        return $this->defaults;
    }

    /**
     * The settings a channel works with. Without a channel, or without an origin that says anything, the
     * configuration's.
     */
    public function forChannel(?ChannelInterface $channel): CarrierSettings
    {
        $code = $channel?->getCode();
        if (null === $channel || null === $code) {
            return $this->defaults;
        }

        return $this->byChannel[$code] ??= $this->resolve($channel);
    }

    /**
     * The shortest time any channel keeps documents for, the configuration's included: where the purge starts
     * looking from, before it asks each document's own channel.
     *
     * @throws InvalidCarrierSettingException When a channel keeps them for less than the minimum
     */
    public function shortestDocumentsRetention(): int
    {
        $shortest = $this->defaults->documentsRetention;

        foreach ($this->originRepository->findAll() as $origin) {
            if (!$origin instanceof CarrierChannelSettingsInterface || null === $own = $origin->getDocumentsRetention()) {
                continue;
            }

            if ($own < CarrierSettings::MIN_SECONDS) {
                throw new InvalidCarrierSettingException(sprintf(
                    'documents_retention of the channel "%s" is %d, and it cannot be less than %d second.',
                    (string) $origin->getChannel()?->getCode(),
                    $own,
                    CarrierSettings::MIN_SECONDS,
                ));
            }

            $shortest = min($shortest, $own);
        }

        return $shortest;
    }

    public function reset(): void
    {
        $this->byChannel = [];
    }

    private function resolve(ChannelInterface $channel): CarrierSettings
    {
        $origin = $this->originRepository->findOneBy(['channel' => $channel]);
        if (!$origin instanceof CarrierChannelSettingsInterface) {
            return $this->defaults;
        }

        $labelFormats = $this->defaults->labelFormats;
        foreach (array_keys(LabelFormats::DEFAULTS) as $carrier) {
            $labelFormat = $origin->getLabelFormat($carrier);
            if (null !== $labelFormat) {
                $labelFormats[$carrier] = $labelFormat;
            }
        }

        return new CarrierSettings(
            $origin->getCarrierTimeout() ?? $this->defaults->carrierTimeout,
            $origin->getRateLifetime() ?? $this->defaults->rateLifetime,
            $origin->getRateRetention() ?? $this->defaults->rateRetention,
            $origin->getTrackingLifetime() ?? $this->defaults->trackingLifetime,
            $origin->getDocumentsRetention() ?? $this->defaults->documentsRetention,
            // Store-wide: a file no row names belongs to no channel.
            $this->defaults->temporaryDocumentsRetention,
            $labelFormats,
        );
    }
}
