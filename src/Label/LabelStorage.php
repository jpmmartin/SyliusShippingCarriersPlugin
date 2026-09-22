<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsDocument;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\IssuedLabel;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Keeps the label files in the plugin's private storage, which lives outside the published directory.
 *
 * A label is written twice over: first to a waiting area, and only once the database has taken the row is it
 * moved to where the row says it is. That way a failure between the two leaves a file nobody has been told
 * about, which the purge collects, instead of a row pointing at nothing.
 *
 * The path of a label is its shipment, the carrier's number for the parcel and its place in the shipment. The
 * tracking number is in it so that issuing a shipment again after cancelling the first one writes somewhere
 * else: the file of a cancelled label is still evidence until the retention takes it.
 *
 * The customs document of a shipment is kept next to its labels, under the same rules: nothing tells it apart
 * from a label but its name.
 */
final readonly class LabelStorage
{
    /** Where a label waits until the row that names it exists. Nothing serves files from here. */
    public const PENDING_DIRECTORY = 'labels/pending';

    public function __construct(
        private FilesystemOperator $storage,
    ) {
    }

    /**
     * @param int|string $shipmentId The shipment the label belongs to
     */
    public function pathFor(IssuedLabel $label, int|string $shipmentId): string
    {
        return sprintf(
            'labels/%s/%s-%d.%s',
            self::slug((string) $shipmentId),
            self::slug($label->trackingNumber),
            $label->position,
            self::extension($label->format),
        );
    }

    /**
     * The carrier's name for the shipment is in it for the same reason the tracking number is in a label's.
     *
     * @param int|string $shipmentId The shipment the document belongs to
     */
    public function pathForCustomsDocument(CustomsDocument $document, int|string $shipmentId, string $carrierReference): string
    {
        return sprintf(
            'labels/%s/customs-%s.%s',
            self::slug((string) $shipmentId),
            self::slug($carrierReference),
            self::extension($document->format),
        );
    }

    /**
     * @return string The path it waits at, to be given to promote() once the row exists
     *
     * @throws FilesystemException When it could not be written
     */
    public function writeTemporary(IssuedLabel|CustomsDocument $file): string
    {
        $path = sprintf('%s/%s.%s', self::PENDING_DIRECTORY, bin2hex(random_bytes(16)), self::extension($file->format));

        $this->storage->write($path, $file->contents);

        return $path;
    }

    /**
     * Moves a label that was waiting to where its row says it is.
     *
     * @throws FilesystemException When it could not be moved
     */
    public function promote(string $temporaryPath, string $path): void
    {
        $this->storage->move($temporaryPath, $path);
    }

    /**
     * Throws away a label nobody was told about. It never fails: it is called while something else is already
     * going wrong, and the purge collects whatever is left behind.
     */
    public function discard(string $temporaryPath): void
    {
        try {
            $this->storage->delete($temporaryPath);
        } catch (FilesystemException) {
            // Left for the purge.
        }
    }

    /**
     * Deleting what is not there is not a failure: what mattered was that it is gone.
     *
     * @throws FilesystemException When it could not be deleted
     */
    public function delete(string $path): void
    {
        $this->storage->delete($path);
    }

    /**
     * Everything kept for one shipment, for when the shipment itself is deleted. Its labels and its customs
     * document all hang from the same directory, so nothing has to be looked up to know what to take.
     *
     * @throws FilesystemException When it could not be deleted
     */
    public function forgetShipment(int|string $shipmentId): void
    {
        $this->storage->deleteDirectory(sprintf('labels/%s', self::slug((string) $shipmentId)));
    }

    /**
     * The files left waiting by an issue that never got as far as its row. Nothing names them, so the only
     * thing that tells an abandoned one from one being written right now is how long it has been there.
     *
     * @param int $untouchedSince Unix time before which a waiting file counts as abandoned
     *
     * @return list<string>
     *
     * @throws FilesystemException When the waiting area could not be read
     */
    public function abandonedTemporaries(int $untouchedSince): array
    {
        $abandoned = [];

        foreach ($this->storage->listContents(self::PENDING_DIRECTORY) as $file) {
            if (!$file->isFile() || ($file->lastModified() ?? 0) >= $untouchedSince) {
                continue;
            }

            $abandoned[] = $file->path();
        }

        return $abandoned;
    }

    /**
     * What the carrier prints is not always a format with an obvious extension — UPS's SPL and EPL are its own
     * — so the format is used as it comes, lowercased, and anything that is not a letter or a digit is dropped
     * rather than trusted in a path.
     */
    private static function extension(string $format): string
    {
        $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $format) ?? '');

        return '' === $extension ? 'bin' : $extension;
    }

    private static function slug(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';

        return '' === $slug ? 'unknown' : $slug;
    }
}
