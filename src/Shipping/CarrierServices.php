<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Shipping;

/**
 * The services an administrator can choose for a shipping method of each carrier, by the carrier's own code.
 *
 * The plugin ships with a few and an application adds more, or renames them, from its configuration:
 *
 *     jpm_martin_sylius_shipping_carriers:
 *         services:
 *             ups:
 *                 '02': 'UPS 2nd Day Air'
 *
 * @internal
 */
final readonly class CarrierServices
{
    /**
     * Only codes the carriers' own API schemas show in their examples. Any other service is one entry away in the
     * application's configuration.
     */
    public const DEFAULTS = [
        'ups' => [
            '01' => 'UPS Next Day Air',
            '03' => 'UPS Ground',
        ],
        'fedex' => [
            'FEDEX_GROUND' => 'FedEx Ground',
            'PRIORITY_OVERNIGHT' => 'FedEx Priority Overnight',
            'STANDARD_OVERNIGHT' => 'FedEx Standard Overnight',
        ],
    ];

    /**
     * @param array<string, array<array-key, string>> $services Names by service code, by carrier
     */
    public function __construct(
        private array $services,
    ) {
    }

    /**
     * PHP turns a code such as "12" into an integer array key, so codes are given back as strings.
     *
     * @return list<string>
     */
    public function codes(string $carrier): array
    {
        return array_map(strval(...), array_keys($this->services[$carrier] ?? []));
    }

    /**
     * Null when the carrier has no such service in the list.
     */
    public function name(string $carrier, string $code): ?string
    {
        return $this->services[$carrier][$code] ?? null;
    }
}
