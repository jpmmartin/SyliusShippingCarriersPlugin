<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;

/**
 * What to ask each carrier to print its labels as.
 *
 * The two carriers share no format. UPS does not issue PDF at all — its SDK lists GIF, ZPL, EPL and SPL —
 * while FedEx does. So the default is the best «office» format each one offers, printable on any printer and
 * readable in any browser, and a warehouse with a thermal printer changes it to ZPL from configuration.
 */
final class LabelFormats
{
    /** Printable anywhere, which is what a shop without a label printer needs. */
    public const DEFAULTS = [
        CarrierCredentialsInterface::CARRIER_UPS => 'GIF',
        CarrierCredentialsInterface::CARRIER_FEDEX => 'PDF',
    ];

    /**
     * UPS's list is the one its SDK documents (`Model/LabelSpecificationLabelImageFormat.php`).
     *
     * FedEx's SDK does not enumerate them: its `imageType` is a free string whose docblock names PDF and
     * ZPLII. Until a real call confirms the rest, only those two are accepted, so a typo is refused here
     * rather than at the carrier with a shipment half issued.
     */
    public const SUPPORTED = [
        CarrierCredentialsInterface::CARRIER_UPS => ['GIF', 'ZPL', 'EPL', 'SPL'],
        CarrierCredentialsInterface::CARRIER_FEDEX => ['PDF', 'ZPLII'],
    ];

    /**
     * @param array<string, string> $formats By carrier code
     */
    public function __construct(
        private readonly array $formats,
    ) {
    }

    public function for(string $carrier): string
    {
        return $this->formats[$carrier] ?? self::DEFAULTS[$carrier] ?? 'PDF';
    }
}
