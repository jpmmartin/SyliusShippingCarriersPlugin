<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierShipmentExportRepositoryInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception\InvalidCarrierSettingException;
use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Throws away the labels and the customs documents that have been kept long enough.
 *
 * They are kept because a shipment can still be cancelled, and because somebody may still have to prove what
 * was sent. Neither reason lasts forever, and what they hold — an address, what a buyer bought — is not ours to
 * keep once it stops being useful. So the file goes and the record stays: who issued what, and when, is still
 * there to be read afterwards.
 *
 * Nothing is deleted the moment it expires: the files go when this is asked to run, which is what makes it
 * something a shop schedules rather than something that happens behind its back.
 *
 * @internal
 */
final readonly class DocumentPurger
{
    /**
     * How many shipments are read at a time. A shop that has been shipping for years has more expired files
     * than fit in memory at once.
     */
    private const BATCH = 100;

    /**
     * @param CarrierShipmentExportRepositoryInterface<CarrierShipmentExportInterface> $exportRepository
     */
    public function __construct(
        private CarrierShipmentExportRepositoryInterface $exportRepository,
        private LabelStorage $labelStorage,
        private ObjectManager $exportManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private CarrierSettingsProvider $settings,
    ) {
    }

    /**
     * A file the storage refuses to delete is left where it is, with its record untouched: the record has to
     * keep saying the file exists for as long as it does, and the next run tries again.
     *
     * @throws InvalidCarrierSettingException When a retention cannot be used, before anything is deleted
     */
    public function purge(): PurgeReport
    {
        // Checked before the first file goes: a retention of zero would delete every document there is.
        $settings = $this->settings->defaults();

        try {
            $settings->assertPurgeUsable();
        } catch (InvalidCarrierSettingException $exception) {
            $this->logger->error('Nothing is purged, because a setting cannot be used: {reason}', [
                'reason' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $expiredBefore = $this->clock->now()->sub(new \DateInterval(sprintf('PT%dS', $settings->documentsRetention)));

        $deleted = 0;
        $failed = 0;
        $shipments = 0;
        $afterId = 0;

        while ([] !== $exports = $this->exportRepository->findWithDocumentsIssuedBefore($expiredBefore, $afterId, self::BATCH)) {
            foreach ($exports as $export) {
                $afterId = (int) $export->getId();
                $gone = $this->purgeExport($export, $failed);

                if (0 === $gone) {
                    continue;
                }

                $deleted += $gone;
                ++$shipments;
            }

            $this->exportManager->flush();
        }

        // Collected first and passed after, because the collection is what counts the last of the failures.
        $temporaries = $this->collectTemporaries($settings->temporaryDocumentsRetention, $failed);

        return new PurgeReport($deleted, $failed, $shipments, $temporaries);
    }

    /**
     * The waiting area holds what an issue wrote before something went wrong between the file and its row. No
     * row names those files, so nothing else will ever come looking for them.
     *
     * @param int $failed Counts up with every file the storage would not delete
     *
     * @return int How many were collected
     */
    private function collectTemporaries(int $temporaryRetention, int &$failed): int
    {
        $untouchedSince = $this->clock->now()->getTimestamp() - $temporaryRetention;

        try {
            $abandoned = $this->labelStorage->abandonedTemporaries($untouchedSince);
        } catch (FilesystemException $exception) {
            $this->logger->error('The waiting area of the carrier documents could not be read, so nothing was collected from it.', [
                'exception' => $exception,
            ]);

            return 0;
        }

        $collected = 0;

        foreach ($abandoned as $path) {
            if ($this->forget($path)) {
                ++$collected;

                continue;
            }

            ++$failed;
        }

        return $collected;
    }

    /**
     * @param int $failed Counts up with every file the storage would not delete
     *
     * @return int How many files of this shipment are gone
     */
    private function purgeExport(CarrierShipmentExportInterface $export, int &$failed): int
    {
        $deleted = 0;

        foreach ($export->getLabels() as $label) {
            $path = $label->getPath();

            if (null === $path || $label->isPurged()) {
                continue;
            }

            if (!$this->forget($path)) {
                ++$failed;

                continue;
            }

            $this->seal($label);
            ++$deleted;
        }

        $customsDocument = $export->getCustomsDocumentPath();

        if (null === $customsDocument || null !== $export->getCustomsDocumentPurgedAt()) {
            return $deleted;
        }

        if (!$this->forget($customsDocument)) {
            ++$failed;

            return $deleted;
        }

        $export->setCustomsDocumentPurgedAt($this->clock->now());

        return $deleted + 1;
    }

    /**
     * The path stays on the record so that anyone reading it afterwards sees there was a file and that it is
     * gone, rather than a shipment that looks as if it was never printed.
     */
    private function seal(CarrierShipmentLabelInterface $label): void
    {
        $label->setPurgedAt($this->clock->now());
    }

    /**
     * @return bool Whether the file is no longer stored
     */
    private function forget(string $path): bool
    {
        try {
            $this->labelStorage->delete($path);

            return true;
        } catch (FilesystemException $exception) {
            $this->logger->error('The carrier document {path} could not be deleted, so it is kept until the next purge.', [
                'path' => $path,
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
