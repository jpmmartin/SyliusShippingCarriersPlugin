<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelCarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\VoidResult;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\NotIssuedException;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception\InvalidCarrierSettingException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cancels the labels of a shipment with the carrier that issued them.
 *
 * Cancelling is told to the carrier first and recorded afterwards, and only if the carrier says it did cancel.
 * A record that says «cancelled» about a shipment the carrier will still bill for is worse than no record: the
 * warehouse stops looking for the parcel and the invoice arrives anyway.
 *
 * @internal
 */
final readonly class LabelVoider implements LabelVoiderInterface
{
    /**
     * @param ContainerInterface $labelCarriers The label adapters, by carrier code
     */
    public function __construct(
        private ContainerInterface $labelCarriers,
        private ObjectManager $exportManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private CarrierSettingsProvider $settings,
    ) {
    }

    public function void(CarrierShipmentExportInterface $export, string $voidedBy): VoidResult
    {
        if (CarrierShipmentExportInterface::STATE_ISSUED !== $export->getState()) {
            throw new NotIssuedException(sprintf(
                'The shipment has no issued labels to cancel: it is %s.',
                $export->getState(),
            ));
        }

        $carrier = (string) $export->getCarrier();
        $reference = (string) $export->getCarrierReference();
        if ('' === $reference) {
            throw new NotIssuedException('The shipment has no carrier reference, so the carrier cannot be told to cancel anything.');
        }

        $labelCarrier = $this->labelCarriers->has($carrier) ? $this->labelCarriers->get($carrier) : null;
        if (!$labelCarrier instanceof LabelCarrierInterface) {
            throw new \LogicException(sprintf('The carrier "%s" does not issue labels, so it cancels none.', $carrier));
        }

        try {
            $this->settings->defaults()->assertCarrierCallsUsable();
        } catch (InvalidCarrierSettingException $exception) {
            $this->logger->error('The carrier is not told to cancel anything, because a setting cannot be used: {reason}', [
                'shipment' => $export->getShipment()?->getId(),
                'reason' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        try {
            $result = $labelCarrier->void($reference);
        } catch (CarrierException $exception) {
            // The carrier could not even be asked, so nothing is known and nothing is recorded.
            $this->logger->error('The carrier {carrier} could not be told to cancel the shipment {shipment} it calls {reference}: {reason}', [
                'carrier' => $carrier,
                'shipment' => $export->getShipment()?->getId(),
                'reference' => $reference,
                'reason' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        if (!$result->voided) {
            return $this->refused($export, $carrier, $reference, (string) $result->reason);
        }

        $export->setState(CarrierShipmentExportInterface::STATE_VOIDED);
        $export->setVoidedAt($this->clock->now());
        $export->setVoidedBy($voidedBy);
        $export->setFailureReason(null);
        $this->exportManager->flush();

        $this->logger->info('The carrier {carrier} cancelled the shipment {reference}, asked by {who}.', [
            'carrier' => $carrier,
            'reference' => $reference,
            'who' => $voidedBy,
        ]);

        return $result;
    }

    /**
     * The carrier said no. The labels stay issued, because the carrier will still bill for them.
     */
    private function refused(CarrierShipmentExportInterface $export, string $carrier, string $reference, string $reason): VoidResult
    {
        $export->setFailureReason($reason);
        $this->exportManager->flush();

        $this->logger->error('The carrier {carrier} refused to cancel the shipment {shipment} it calls {reference}, which is still issued: {reason}', [
            'carrier' => $carrier,
            'shipment' => $export->getShipment()?->getId(),
            'reference' => $reference,
            'reason' => $reason,
        ]);

        return VoidResult::refused($reason);
    }
}
