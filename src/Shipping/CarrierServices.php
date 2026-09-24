<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Shipping;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierChannelSettingsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Contracts\Service\ResetInterface;

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
 * A channel adds some of its own, or renames some, on its shipping origin. The list of a channel is the
 * configuration's and its own; what a shipping method is chosen from is every list, since a method may be offered
 * in several channels, and each of them is checked when it is saved.
 *
 * @internal
 */
final class CarrierServices implements ResetInterface
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
     * What each channel adds, by channel code and then carrier, in order of channel code. Read once per request,
     * because an administrator may change it between two.
     *
     * @var array<string, array<string, array<array-key, string>>>|null
     */
    private ?array $byChannel = null;

    /**
     * @param array<string, array<array-key, string>> $services Names by service code, by carrier
     * @param RepositoryInterface<CarrierShippingOriginInterface>|null $originRepository Where the channels' own
     *                                                                                   come from; none has any
     *                                                                                   without it
     */
    public function __construct(
        private readonly array $services,
        private readonly ?RepositoryInterface $originRepository = null,
    ) {
    }

    /**
     * Every service a shipping method may be given: the configuration's and every channel's. PHP turns a code such
     * as "12" into an integer array key, so codes are given back as strings.
     *
     * @return list<string>
     */
    public function codes(string $carrier): array
    {
        $codes = array_map(strval(...), array_keys($this->services[$carrier] ?? []));
        foreach ($this->byChannel() as $services) {
            foreach (array_keys($services[$carrier] ?? []) as $code) {
                $codes[] = (string) $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * The name the configuration gives it or, if only channels have it, the first of them by channel code. Null when
     * no list has it.
     */
    public function name(string $carrier, string $code): ?string
    {
        if (isset($this->services[$carrier][$code])) {
            return $this->services[$carrier][$code];
        }

        foreach ($this->byChannel() as $services) {
            if (isset($services[$carrier][$code])) {
                return $services[$carrier][$code];
            }
        }

        return null;
    }

    /**
     * The name in the channel's list: its own, or the configuration's. Null when the channel's list does not have it.
     */
    public function nameInChannel(string $carrier, string $code, ChannelInterface $channel): ?string
    {
        return $this->byChannel()[(string) $channel->getCode()][$carrier][$code] ?? $this->services[$carrier][$code] ?? null;
    }

    public function reset(): void
    {
        $this->byChannel = null;
    }

    /**
     * @return array<string, array<string, array<array-key, string>>>
     */
    private function byChannel(): array
    {
        if (null !== $this->byChannel) {
            return $this->byChannel;
        }

        $byChannel = [];
        foreach ($this->originRepository?->findAll() ?? [] as $origin) {
            if (!$origin instanceof CarrierChannelSettingsInterface) {
                continue;
            }

            foreach (array_keys(self::DEFAULTS) as $carrier) {
                $own = $origin->getServices($carrier);
                if ([] !== $own) {
                    $byChannel[(string) $origin->getChannel()?->getCode()][$carrier] = $own;
                }
            }
        }

        ksort($byChannel);

        return $this->byChannel = $byChannel;
    }
}
