<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the customs document of a shipment to an authorised administrator, under the same rules as its labels.
 *
 * It goes by the export's own record for the same reason a label does: the paperwork of a cancelled shipment
 * declares a parcel the carrier no longer has.
 *
 * @internal
 */
final readonly class DownloadCarrierCustomsDocumentAction
{
    /**
     * @param RepositoryInterface<CarrierShipmentExportInterface> $exportRepository
     */
    public function __construct(
        private RepositoryInterface $exportRepository,
        private DownloadCarrierDocumentAction $downloadDocument,
    ) {
    }

    /**
     * @param int $id The export's, not the shipment's
     */
    public function __invoke(int $id): Response
    {
        $export = $this->exportRepository->find($id);
        if (!$export instanceof CarrierShipmentExportInterface) {
            throw new NotFoundHttpException(sprintf('There is no exported shipment %d.', $id));
        }

        if (CarrierShipmentExportInterface::STATE_ISSUED !== $export->getState()) {
            throw new NotFoundHttpException(sprintf('The shipment %d is not issued, so it has no customs document to hand over.', $id));
        }

        $path = $export->getCustomsDocumentPath();
        if (null === $path || null !== $export->getCustomsDocumentPurgedAt()) {
            throw new NotFoundHttpException(sprintf('The shipment %d has no customs document, or it is no longer kept.', $id));
        }

        return ($this->downloadDocument)($path);
    }
}
