<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Repository;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;

/**
 * @template T of CarrierPackageBoxInterface
 * @implements CarrierPackageBoxRepositoryInterface<T>
 */
class CarrierPackageBoxRepository extends EntityRepository implements CarrierPackageBoxRepositoryInterface
{
    public function findApplicableToOrigin(CarrierShippingOriginInterface $origin): array
    {
        $boxes = $origin->getBoxes()->toArray();

        if ([] === $boxes) {
            /** @var list<CarrierPackageBoxInterface> $boxes */
            $boxes = $this->findBy([], ['id' => 'ASC']);

            return $boxes;
        }

        usort($boxes, static fn (CarrierPackageBoxInterface $left, CarrierPackageBoxInterface $right): int => $left->getId() <=> $right->getId());

        return $boxes;
    }
}
