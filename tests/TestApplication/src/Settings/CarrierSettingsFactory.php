<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Settings;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettings;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * The plugin's settings with its defaults, changing only what a test is about.
 */
final class CarrierSettingsFactory
{
    /**
     * @param array<string, string> $labelFormats
     * @param RepositoryInterface<CarrierShippingOriginInterface>|null $originRepository Where the channels' own
     *                                                                                   settings come from; none
     *                                                                                   has any without it
     */
    public static function provider(
        float $carrierTimeout = 10.0,
        int $rateLifetime = 900,
        int $rateRetention = 86400,
        int $trackingLifetime = 300,
        int $documentsRetention = 180 * 24 * 60 * 60,
        int $temporaryDocumentsRetention = 24 * 60 * 60,
        array $labelFormats = [],
        ?RepositoryInterface $originRepository = null,
    ): CarrierSettingsProvider {
        return new CarrierSettingsProvider(
            new CarrierSettings(
                $carrierTimeout,
                $rateLifetime,
                $rateRetention,
                $trackingLifetime,
                $documentsRetention,
                $temporaryDocumentsRetention,
                $labelFormats,
            ),
            $originRepository ?? self::noOrigins(),
        );
    }

    /**
     * @return RepositoryInterface<CarrierShippingOriginInterface>
     */
    private static function noOrigins(): RepositoryInterface
    {
        /** @var RepositoryInterface<CarrierShippingOriginInterface> $repository */
        $repository = new class() implements RepositoryInterface {
            public function find($id): ?object
            {
                return null;
            }

            public function findAll(): array
            {
                return [];
            }

            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
            {
                return [];
            }

            public function findOneBy(array $criteria): ?object
            {
                return null;
            }

            public function getClassName(): string
            {
                return CarrierShippingOriginInterface::class;
            }

            public function createPaginator(array $criteria = [], array $sorting = []): iterable
            {
                return [];
            }

            public function add(ResourceInterface $resource): void
            {
            }

            public function remove(ResourceInterface $resource): void
            {
            }
        };

        return $repository;
    }
}
