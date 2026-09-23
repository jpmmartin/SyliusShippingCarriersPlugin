<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelCarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentResult;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AlreadyIssuedException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AmbiguousShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\UnissuableShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShipmentCarrier;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Issues the labels of one shipment: asks its carrier, keeps the files and records what happened.
 *
 * There are three endings, not two, and the third one is the reason this class is careful. A carrier that says
 * no has issued nothing, and the shipment can be fixed and sent again. A carrier that says nothing — a timeout,
 * a body that cannot be read — may well have issued and charged for the labels, and sending the request again
 * pays for a second shipment. That case is kept apart as «needs check» so that nobody, and nothing, retries it
 * on its own.
 *
 * @internal
 */
final readonly class LabelIssuer implements LabelIssuerInterface
{
    /**
     * @param ContainerInterface $labelCarriers The label adapters, by carrier code
     * @param RepositoryInterface<CarrierShipmentExportInterface> $exportRepository
     * @param FactoryInterface<CarrierShipmentExportInterface> $exportFactory
     * @param FactoryInterface<CarrierShipmentLabelInterface> $labelFactory
     */
    public function __construct(
        private ShipmentCarrier $shipmentCarrier,
        private ContainerInterface $labelCarriers,
        private ShipmentRequestFactory $requestFactory,
        private CredentialsProvider $credentialsProvider,
        private LabelStorage $labelStorage,
        private RepositoryInterface $exportRepository,
        private FactoryInterface $exportFactory,
        private FactoryInterface $labelFactory,
        private ObjectManager $exportManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $issuedBy Who asked for it, as it is shown afterwards
     *
     * @throws \InvalidArgumentException When the shipment is not one of a carrier of this plugin. The admin
     *                                   never offers the action for one, so getting here is a mistake, not a
     *                                   failed issue
     * @throws AlreadyIssuedException When the shipment already has its labels. Reissuing means cancelling
     *                                them first
     * @throws AmbiguousShipmentException When nobody knows yet whether a previous attempt was issued. This is
     *                                   the refusal that stops the same shipment being paid for twice
     */
    public function issue(ShipmentInterface $shipment, string $issuedBy): CarrierShipmentExportInterface
    {
        $carrier = $this->shipmentCarrier->of($shipment);
        if (null === $carrier) {
            throw new \InvalidArgumentException('The shipment is not sent by a carrier of this plugin, so no label is issued for it.');
        }

        $export = $this->export($shipment, $carrier);

        // A label that exists is never overwritten in silence: the parcel may already be on its way under a
        // number this would lose, and the carrier would bill for both. Cancelling it first is what frees it.
        if (CarrierShipmentExportInterface::STATE_ISSUED === $export->getState()) {
            throw new AlreadyIssuedException(sprintf(
                'The shipment already has the labels %s issued as "%s". Cancel them before issuing new ones.',
                $carrier,
                (string) $export->getCarrierReference(),
            ));
        }

        // Refused here and not only in the admin: a shipment nobody knows the fate of is exactly the one that
        // gets paid for twice, and a batch, a command or another plugin must hit the same wall the operator does.
        if (CarrierShipmentExportInterface::STATE_NEEDS_CHECK === $export->getState()) {
            throw new AmbiguousShipmentException(sprintf(
                'Nobody knows whether %s issued the labels of this shipment, so it is not sent again until somebody says it was not: %s',
                $carrier,
                (string) $export->getFailureReason(),
            ));
        }

        $labelCarrier = $this->labelCarriers->get($carrier);
        if (!$labelCarrier instanceof LabelCarrierInterface) {
            throw new \LogicException(sprintf('The carrier "%s" does not issue labels.', $carrier));
        }

        // A name of its own for this attempt, kept before anything is sent: after a request that goes
        // unanswered it is the only thing left to ask the carrier about.
        $ownReference = bin2hex(random_bytes(12));
        $export->setOwnReference($ownReference);

        try {
            $credentials = $this->credentialsProvider->get($carrier);
            $export->setEnvironment($credentials->getEnvironment());

            $request = $this->requestFactory->create($shipment, $carrier, $ownReference);
        } catch (CarrierCredentialsException | UnissuableShipmentException $exception) {
            // Nothing was asked of the carrier, so nothing was issued and nothing was charged.
            return $this->failed($export, $shipment, $carrier, $exception->getMessage());
        }

        try {
            $result = $labelCarrier->ship($request);
        } catch (CarrierRejectedRequestException | CarrierCredentialsException $exception) {
            // The carrier answered, and the answer was no.
            return $this->failed($export, $shipment, $carrier, $exception->getMessage());
        } catch (CarrierException $exception) {
            // No usable answer came back. Whether the carrier issued the labels is exactly what nobody knows,
            // so the carrier is asked straight away about the name the plugin gave it.
            $recovered = $this->recover($labelCarrier, $ownReference, $shipment, $carrier);
            if (null === $recovered) {
                return $this->needsCheck($export, $shipment, $carrier, $exception->getMessage());
            }

            return $this->issued($export, $shipment, $carrier, $issuedBy, $request, $recovered);
        }

        return $this->issued($export, $shipment, $carrier, $issuedBy, $request, $result);
    }

    /**
     * Says that a shipment nobody knew the fate of was never issued, so it can be sent again.
     *
     * It takes a person, because it is the one thing the plugin cannot find out on its own: the carrier did
     * not answer, and this carrier cannot be asked. Whoever signs this has looked in the carrier's own portal.
     *
     * @param string $confirmedBy Who looked and said so
     *
     * @throws \InvalidArgumentException When the shipment was not waiting to be checked
     */
    public function confirmNotIssued(CarrierShipmentExportInterface $export, string $confirmedBy): void
    {
        if (CarrierShipmentExportInterface::STATE_NEEDS_CHECK !== $export->getState()) {
            throw new \InvalidArgumentException('Only a shipment waiting to be checked is confirmed as never issued.');
        }

        $export->setState(CarrierShipmentExportInterface::STATE_FAILED);
        $export->setFailureReason(sprintf('%s confirmed that the carrier never issued it: %s', $confirmedBy, (string) $export->getFailureReason()));
        $this->save($export);

        $this->logger->warning('{who} confirmed that {carrier} never issued the labels of the shipment {shipment}, so it may be sent again.', [
            'who' => $confirmedBy,
            'carrier' => (string) $export->getCarrier(),
            'shipment' => $export->getShipment()?->getId(),
        ]);
    }

    /**
     * Asks the carrier whether it did issue what it never answered about. Null when it says no, when it
     * cannot be asked at all — FedEx has no such operation — or when asking fails too: all three mean the
     * same thing here, that a person has to look.
     */
    private function recover(
        LabelCarrierInterface $labelCarrier,
        string $ownReference,
        ShipmentInterface $shipment,
        string $carrier,
    ): ?ShipmentResult {
        try {
            return $labelCarrier->recover($ownReference);
        } catch (CarrierException $exception) {
            $this->logger->error('The carrier {carrier} could not be asked whether it issued the labels of the shipment {shipment}: {reason}', [
                'carrier' => $carrier,
                'shipment' => $shipment->getId(),
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function export(ShipmentInterface $shipment, string $carrier): CarrierShipmentExportInterface
    {
        $export = $this->exportRepository->findOneBy(['shipment' => $shipment]);
        if (!$export instanceof CarrierShipmentExportInterface) {
            $export = $this->exportFactory->createNew();
            $export->setShipment($shipment);
        }

        $export->setCarrier($carrier);

        return $export;
    }

    private function issued(
        CarrierShipmentExportInterface $export,
        ShipmentInterface $shipment,
        string $carrier,
        string $issuedBy,
        ShipmentRequest $request,
        ShipmentResult $result,
    ): CarrierShipmentExportInterface {
        // Written where nothing serves them from, moved into place only once the database has taken the rows:
        // a failure in between must leave neither a row without its file nor a file without its row.
        $waiting = [];
        // The customs document of an attempt that was cancelled: the export is about to point at the new one.
        $previousCustomsDocument = $export->getCustomsDocumentPath();

        try {
            foreach ($result->labels as $issuedLabel) {
                $package = $request->packages[$issuedLabel->position] ?? null;
                $path = $this->labelStorage->pathFor($issuedLabel, (string) $shipment->getId());

                $label = $this->labelFactory->createNew();
                $label->setPosition($issuedLabel->position);
                $label->setFormat($issuedLabel->format);
                $label->setTrackingNumber($issuedLabel->trackingNumber);
                $label->setDeclaredValue($package?->declaredValue);
                $label->setDeclaredValueCurrency($package?->declaredValueCurrency);
                $label->setPath($path);

                $export->addLabel($label);
                $waiting[$this->labelStorage->writeTemporary($issuedLabel)] = $path;
            }

            $export->setCustomsDocumentPath(null);
            $export->setCustomsDocumentFormat(null);
            $export->setCustomsDocumentPurgedAt(null);
            if (null !== $result->customsDocument) {
                $path = $this->labelStorage->pathForCustomsDocument($result->customsDocument, (string) $shipment->getId(), $result->carrierReference);
                $export->setCustomsDocumentPath($path);
                $export->setCustomsDocumentFormat($result->customsDocument->format);
                $waiting[$this->labelStorage->writeTemporary($result->customsDocument)] = $path;
            }

            $export->setState(CarrierShipmentExportInterface::STATE_ISSUED);
            $export->setCarrierReference($result->carrierReference);
            $export->setIssuedAt($this->clock->now());
            $export->setIssuedBy($issuedBy);
            $export->setFailureReason(null);

            // Nobody types a tracking number that the carrier has just given: the tracking of the shop reads this one.
            $shipment->setTracking($result->carrierReference);

            $this->save($export);
        } catch (\Throwable $exception) {
            foreach (array_keys($waiting) as $temporaryPath) {
                $this->labelStorage->discard($temporaryPath);
            }

            throw $exception;
        }

        $this->promote($waiting, $shipment, $carrier);
        $this->forget($previousCustomsDocument, $export, $shipment);

        // The labels are paid for and kept all the same: a shipment is not un-issued because the paperwork
        // is missing. But whoever hands it over has to know there is none to print.
        if (null !== $request->customsInvoice && null === $result->customsDocument) {
            $this->logger->warning('The carrier {carrier} issued the shipment {shipment}, which crosses a border, without any customs document.', [
                'carrier' => $carrier,
                'shipment' => $shipment->getId(),
                'reference' => $result->carrierReference,
            ]);
        }

        $this->logger->info('The carrier {carrier} issued {labels} label(s) for the shipment {shipment}.', [
            'carrier' => $carrier,
            'labels' => \count($result->labels),
            'shipment' => $shipment->getId(),
            'reference' => $result->carrierReference,
        ]);

        return $export;
    }

    private function failed(
        CarrierShipmentExportInterface $export,
        ShipmentInterface $shipment,
        string $carrier,
        string $reason,
    ): CarrierShipmentExportInterface {
        $export->setState(CarrierShipmentExportInterface::STATE_FAILED);
        $export->setFailureReason($reason);
        $this->save($export);

        $this->logger->error('The carrier {carrier} did not issue the labels of the shipment {shipment}: {reason}', [
            'carrier' => $carrier,
            'shipment' => $shipment->getId(),
            'reason' => $reason,
        ]);

        return $export;
    }

    private function needsCheck(
        CarrierShipmentExportInterface $export,
        ShipmentInterface $shipment,
        string $carrier,
        string $reason,
    ): CarrierShipmentExportInterface {
        $export->setState(CarrierShipmentExportInterface::STATE_NEEDS_CHECK);
        $export->setFailureReason($reason);
        $this->save($export);

        $this->logger->error('Nobody knows whether the carrier {carrier} issued the labels of the shipment {shipment}, so it must be checked before it is tried again: {reason}', [
            'carrier' => $carrier,
            'shipment' => $shipment->getId(),
            'reason' => $reason,
        ]);

        return $export;
    }

    /**
     * The rows exist, so the files go where the rows say. A move that fails now leaves a label that cannot be
     * downloaded, which is worth shouting about but is not worth pretending the shipment was never issued: it
     * was, and it has been paid for.
     *
     * @param array<string, string> $waiting Temporary path to final path
     */
    private function promote(array $waiting, ShipmentInterface $shipment, string $carrier): void
    {
        foreach ($waiting as $temporaryPath => $path) {
            try {
                $this->labelStorage->promote($temporaryPath, $path);
            } catch (FilesystemException $exception) {
                $this->logger->error('The file {path} of the shipment {shipment} was issued by {carrier} but could not be stored: {reason}', [
                    'path' => $path,
                    'shipment' => $shipment->getId(),
                    'carrier' => $carrier,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Deletes the customs document of a cancelled attempt once the export points at another one. Nothing names
     * it any more, and a file that nothing names is a copy of the buyer's address kept for no one.
     */
    private function forget(?string $previousPath, CarrierShipmentExportInterface $export, ShipmentInterface $shipment): void
    {
        if (null === $previousPath || $previousPath === $export->getCustomsDocumentPath()) {
            return;
        }

        try {
            $this->labelStorage->delete($previousPath);
        } catch (FilesystemException $exception) {
            $this->logger->error('The customs document {path} of a cancelled attempt at the shipment {shipment} could not be deleted: {reason}', [
                'path' => $previousPath,
                'shipment' => $shipment->getId(),
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function save(CarrierShipmentExportInterface $export): void
    {
        $this->exportManager->persist($export);
        $this->exportManager->flush();
    }
}
