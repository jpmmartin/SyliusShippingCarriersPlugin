<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Controller\Admin;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves one issued label to an authorised administrator.
 *
 * It goes by the label's own record rather than by a path, because what may be downloaded depends on what
 * happened to the shipment: a label the carrier cancelled is not a label any more, and handing it over would
 * put a parcel on a van under a number that no longer exists.
 */
final readonly class DownloadCarrierLabelAction
{
    /**
     * @param RepositoryInterface<CarrierShipmentLabelInterface> $labelRepository
     */
    public function __construct(
        private RepositoryInterface $labelRepository,
        private DownloadCarrierDocumentAction $downloadDocument,
    ) {
    }

    public function __invoke(int $id): Response
    {
        $label = $this->labelRepository->find($id);
        if (!$label instanceof CarrierShipmentLabelInterface) {
            throw new NotFoundHttpException(sprintf('There is no label %d.', $id));
        }

        if ($label->isPurged() || null === $label->getPath()) {
            throw new NotFoundHttpException(sprintf('The label %d is no longer kept.', $id));
        }

        if (CarrierShipmentExportInterface::STATE_ISSUED !== $label->getExport()?->getState()) {
            throw new NotFoundHttpException(sprintf('The label %d is not an issued label any more.', $id));
        }

        return ($this->downloadDocument)($label->getPath());
    }
}
