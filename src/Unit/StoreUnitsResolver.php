<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Unit;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

final readonly class StoreUnitsResolver implements StoreUnitsResolverInterface
{
    /** @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository */
    public function __construct(
        private RepositoryInterface $originRepository,
    ) {
    }

    public function getWeightUnit(): string
    {
        return $this->anyOrigin()?->getWeightUnit() ?? CarrierShippingOriginInterface::WEIGHT_UNIT_LB;
    }

    public function getDimensionUnit(): string
    {
        return $this->anyOrigin()?->getDimensionUnit() ?? CarrierShippingOriginInterface::DIMENSION_UNIT_IN;
    }

    /**
     * Every origin declares the same units, so any of them tells. Without origins, the store is read in
     * the units a new origin starts with (imperial).
     */
    private function anyOrigin(): ?CarrierShippingOriginInterface
    {
        $origin = $this->originRepository->findBy([], ['id' => 'ASC'], 1)[0] ?? null;

        return $origin instanceof CarrierShippingOriginInterface ? $origin : null;
    }
}
