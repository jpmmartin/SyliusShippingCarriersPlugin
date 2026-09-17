<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Repository;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @template T of CarrierPackageBoxInterface
 * @extends RepositoryInterface<T>
 */
interface CarrierPackageBoxRepositoryInterface extends RepositoryInterface
{
    /**
     * The boxes the origin is restricted to, or the whole catalog when it has none. Always
     * in the same order, so packaging the same content gives the same packages.
     *
     * @return list<CarrierPackageBoxInterface>
     */
    public function findApplicableToOrigin(CarrierShippingOriginInterface $origin): array;
}
