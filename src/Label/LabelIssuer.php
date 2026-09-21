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
 */
final readonly class LabelIssuer
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
     */
    public function issue(ShipmentInterface $shipment, string $issuedBy): CarrierShipmentExportInterface
    {
        $carrier = $this->shipmentCarrier->of($shipment);
        if (null === $carrier) {
            throw new \InvalidArgumentException('The shipment is not sent by a carrier of this plugin, so no label is issued for it.');
        }

        $export = $this->export($shipment, $carrier);

        try {
            $credentials = $this->credentialsProvider->get($carrier);
            $export->setEnvironment($credentials->getEnvironment());

            $request = $this->requestFactory->create($shipment, $carrier);
        } catch (CarrierCredentialsException | UnissuableShipmentException $exception) {
            // Nothing was asked of the carrier, so nothing was issued and nothing was charged.
            return $this->failed($export, $shipment, $carrier, $exception->getMessage());
        }

        $labelCarrier = $this->labelCarriers->get($carrier);
        if (!$labelCarrier instanceof LabelCarrierInterface) {
            throw new \LogicException(sprintf('The carrier "%s" does not issue labels.', $carrier));
        }

        try {
            $result = $labelCarrier->ship($request);
        } catch (CarrierRejectedRequestException | CarrierCredentialsException $exception) {
            // The carrier answered, and the answer was no.
            return $this->failed($export, $shipment, $carrier, $exception->getMessage());
        } catch (CarrierException $exception) {
            // No usable answer came back. Whether the carrier issued the labels is exactly what nobody knows.
            return $this->needsCheck($export, $shipment, $carrier, $exception->getMessage());
        }

        return $this->issued($export, $shipment, $carrier, $issuedBy, $request, $result);
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
                $this->logger->error('The label {path} of the shipment {shipment} was issued by {carrier} but could not be stored: {reason}', [
                    'path' => $path,
                    'shipment' => $shipment->getId(),
                    'carrier' => $carrier,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function save(CarrierShipmentExportInterface $export): void
    {
        $this->exportManager->persist($export);
        $this->exportManager->flush();
    }
}
