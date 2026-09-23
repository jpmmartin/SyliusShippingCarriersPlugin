<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsDataInterface;

/**
 * Which variants of the catalogue customs could not be told about.
 *
 * A catalogue of thousands of variants cannot be audited one by one, and what is missing is only discovered at
 * the border unless somebody can list it.
 *
 * @internal
 */
final readonly class IncompleteCustomsDataRepository
{
    /**
     * @param class-string $variantClass
     * @param class-string<CarrierCustomsDataInterface> $customsDataClass
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $variantClass,
        private string $customsDataClass,
    ) {
    }

    /**
     * Incomplete means either no customs data at all, or customs data with an empty field: both leave a
     * shipment that cannot be declared.
     */
    public function createIncompleteListQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('variant')
            ->from($this->variantClass, 'variant')
            ->leftJoin($this->customsDataClass, 'customs', 'WITH', 'customs.variant = variant')
            ->andWhere('customs.id IS NULL OR customs.hsCode IS NULL OR customs.hsCode = :empty OR customs.countryOfOrigin IS NULL OR customs.countryOfOrigin = :empty')
            ->setParameter('empty', '')
        ;
    }
}
