<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Psr\Clock\ClockInterface;

/**
 * How long each carrier gives to cancel a shipment.
 *
 * The difference is not a nuance: ninety days against twelve hours. The numbers come from each carrier's own
 * documentation and they are the reason the admin shows the window at all.
 *
 * @internal
 */
final readonly class VoidWindow
{
    /** What the plugin can cancel on its own, in seconds from the moment the labels were issued. */
    private const SELF_SERVICE_SECONDS = [
        // UPS cancels a shipment by itself for ninety days.
        CarrierCredentialsInterface::CARRIER_UPS => 90 * 86400,
        // FedEx gives twelve hours, on the ship date printed on the label or before.
        CarrierCredentialsInterface::CARRIER_FEDEX => 12 * 3600,
    ];

    /** What the carrier still cancels afterwards, but only by talking to them. */
    private const ASSISTED_SECONDS = [
        // Between ninety and a hundred and eighty days UPS has to be contacted; after that, nobody cancels it.
        CarrierCredentialsInterface::CARRIER_UPS => 180 * 86400,
    ];

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    /**
     * Null when this carrier's window is not known, which is how a carrier added later says «do not guess».
     */
    public function of(string $carrier, \DateTimeImmutable $issuedAt): ?VoidWindowState
    {
        $selfService = self::SELF_SERVICE_SECONDS[$carrier] ?? null;
        if (null === $selfService) {
            return null;
        }

        $assisted = self::ASSISTED_SECONDS[$carrier] ?? null;
        $selfServiceUntil = $issuedAt->modify(sprintf('+%d seconds', $selfService));

        return new VoidWindowState(
            $selfServiceUntil,
            null === $assisted ? null : $issuedAt->modify(sprintf('+%d seconds', $assisted)),
            max(0, $selfServiceUntil->getTimestamp() - $this->clock->now()->getTimestamp()),
        );
    }
}
