<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

/**
 * What a channel says in place of the plugin's configuration: how long a carrier is waited for, how long rates,
 * statuses and documents are kept, what labels are printed as and which services can be chosen. Every value is
 * optional, and an empty one leaves the configuration's in force.
 *
 * The shipping origin implements it, because the origin is already the one thing a channel has of its own. It is
 * kept apart from {@see CarrierShippingOriginInterface} so that a store whose own origin model implements only that
 * one goes on working: its channels simply take the configuration.
 */
interface CarrierChannelSettingsInterface
{
    public function getCarrierTimeout(): ?float;

    public function setCarrierTimeout(?float $carrierTimeout): void;

    public function getRateLifetime(): ?int;

    public function setRateLifetime(?int $rateLifetime): void;

    public function getRateRetention(): ?int;

    public function setRateRetention(?int $rateRetention): void;

    public function getTrackingLifetime(): ?int;

    public function setTrackingLifetime(?int $trackingLifetime): void;

    public function getDocumentsRetention(): ?int;

    public function setDocumentsRetention(?int $documentsRetention): void;

    public function getLabelFormat(string $carrier): ?string;

    /**
     * @throws \InvalidArgumentException When the carrier is not one of the plugin's
     */
    public function setLabelFormat(string $carrier, ?string $labelFormat): void;

    /**
     * The services this channel adds to the carrier's list, or renames in it.
     *
     * @return array<string, string> The name shown, by the carrier's service code
     */
    public function getServices(string $carrier): array;

    /**
     * @param array<string, string> $services The name shown, by the carrier's service code
     *
     * @throws \InvalidArgumentException When the carrier is not one of the plugin's
     */
    public function setServices(string $carrier, array $services): void;
}
