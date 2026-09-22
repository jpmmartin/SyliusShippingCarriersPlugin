<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Repository;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @template T of CarrierShipmentExportInterface
 * @extends RepositoryInterface<T>
 */
interface CarrierShipmentExportRepositoryInterface extends RepositoryInterface
{
    /**
     * The exports issued before the given moment that still keep a file: a label, a customs document, or both.
     *
     * Read in batches by ascending id rather than all at once, because a shop that has been shipping for years
     * has more of these than fit in memory. The caller walks them by passing the last id it saw, which keeps it
     * moving even over an export whose file could not be deleted.
     *
     * @param int $afterId Exports up to this id are left out; 0 starts from the beginning
     * @param positive-int $limit
     *
     * @return list<CarrierShipmentExportInterface>
     */
    public function findWithDocumentsIssuedBefore(\DateTimeImmutable $moment, int $afterId, int $limit): array;
}
