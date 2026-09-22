<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Repository;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;

/**
 * @template T of CarrierShipmentExportInterface
 * @implements CarrierShipmentExportRepositoryInterface<T>
 */
class CarrierShipmentExportRepository extends EntityRepository implements CarrierShipmentExportRepositoryInterface
{
    public function findWithDocumentsIssuedBefore(\DateTimeImmutable $moment, int $afterId, int $limit): array
    {
        /** @var list<CarrierShipmentExportInterface> $exports */
        $exports = $this->createQueryBuilder('export')
            ->andWhere('export.id > :afterId')
            // An export that was never issued has no moment to count from, and NULL is never less than one.
            ->andWhere('export.issuedAt < :moment')
            ->andWhere(
                'export.customsDocumentPath IS NOT NULL AND export.customsDocumentPurgedAt IS NULL' .
                ' OR EXISTS (' .
                'SELECT label.id FROM ' . $this->getEntityName() . ' kept' .
                ' INNER JOIN kept.labels label' .
                ' WHERE kept = export AND label.path IS NOT NULL AND label.purgedAt IS NULL' .
                ')',
            )
            ->setParameter('afterId', $afterId)
            ->setParameter('moment', $moment)
            ->orderBy('export.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;

        return $exports;
    }
}
